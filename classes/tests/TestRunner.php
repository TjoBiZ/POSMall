<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Tests;

use Backend\Models\User as BackendUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use KodZero\POSMall\Models\TestRun;
use KodZero\POSMall\Models\TestSettings;
use Symfony\Component\Process\Process;
use Throwable;

class TestRunner
{
    private const ERROR_LIMIT = 12000;

    public function discover(): array
    {
        return [
            'backend' => $this->discoverIn(base_path('plugins/kodzero/posmall/tests'), 'backend'),
            'dusk'    => $this->discoverIn(base_path('tests/Browser'), 'dusk'),
        ];
    }

    public function selectedTests(bool $all = false): array
    {
        $tests = $this->discover();

        if ($all) {
            return $tests;
        }

        return [
            'backend' => $this->filterSelected($tests['backend'], TestSettings::selectedBackendTests(), 'backend'),
            'dusk'    => $this->filterSelected($tests['dusk'], TestSettings::selectedDuskTests(), 'dusk'),
        ];
    }

    public function defaultSelectedPaths(string $type): array
    {
        $tests = $this->discover()[$type] ?? [];

        return array_values(array_map(function (array $test) {
            return $test['path'];
        }, array_filter($tests, fn (array $test) => $this->isImportantDefault($test))));
    }

    public function run(array $tests, string $trigger, ?int $backendUserId = null): TestRun
    {
        $trigger = $this->normalizeTrigger($trigger);
        $startedAt = Carbon::now();
        $selected = array_merge($tests['backend'] ?? [], $tests['dusk'] ?? []);
        $backendUser = $backendUserId ? BackendUser::find($backendUserId) : null;

        $run = TestRun::create([
            'trigger'              => $trigger,
            'backend_user_id'      => $backendUser ? $backendUser->id : null,
            'backend_user_name'    => $backendUser ? trim($backendUser->first_name . ' ' . $backendUser->last_name) : null,
            'status'               => TestRun::STATUS_RUNNING,
            'started_at'           => $startedAt,
            'selected_tests_count' => count($selected),
            'backend_tests_count'  => count($tests['backend'] ?? []),
            'dusk_tests_count'     => count($tests['dusk'] ?? []),
            'selected_tests'       => array_column($selected, 'display'),
            'failing_tests'        => [],
        ]);

        $logPath = $this->prepareLogPath($run);
        $run->log_path = $logPath;
        $run->save();

        $errorOutput = '';
        $failingTests = [];

        $this->append($logPath, 'POSMall test run #' . $run->id . PHP_EOL);
        $this->append($logPath, 'Trigger: ' . $trigger . PHP_EOL);
        $this->append($logPath, 'Started: ' . $startedAt->toDateTimeString() . PHP_EOL);
        $this->append($logPath, $this->duskUserNote() . PHP_EOL);

        if (count($selected) < 1) {
            $errorOutput = 'No tests were selected.';
            $this->append($logPath, $errorOutput . PHP_EOL);
            $failingTests[] = 'No tests selected';
        }

        try {
            foreach ($tests['backend'] ?? [] as $test) {
                if (!$this->runProcess($run, $logPath, $test, $this->phpunitCommand($test), $errorOutput)) {
                    $failingTests[] = $test['display'];
                }
            }

            foreach ($tests['dusk'] ?? [] as $test) {
                if (!$this->runProcess($run, $logPath, $test, $this->duskCommand($test), $errorOutput)) {
                    $failingTests[] = $test['display'];
                }
            }

            $status = count($failingTests) > 0 ? TestRun::STATUS_FAILED : TestRun::STATUS_PASSED;
        } catch (Throwable $e) {
            $status = TestRun::STATUS_ERROR;
            $failingTests[] = 'Runner error';
            $errorOutput .= PHP_EOL . $e->getMessage();
            $this->append($logPath, PHP_EOL . 'Runner error: ' . $e->getMessage() . PHP_EOL);
        }

        $finishedAt = Carbon::now();
        $run->status = $status;
        $run->finished_at = $finishedAt;
        $run->duration_seconds = (int)abs($finishedAt->getTimestamp() - $startedAt->getTimestamp());
        $run->failing_tests = $failingTests;
        $run->error_output = $this->limitText($errorOutput, self::ERROR_LIMIT);
        $run->save();

        $this->append($logPath, PHP_EOL . 'Finished: ' . $finishedAt->toDateTimeString() . PHP_EOL);
        $this->append($logPath, 'Status: ' . $status . PHP_EOL);

        $this->sendFailureEmail($run);

        return $run;
    }

    private function normalizeTrigger(string $trigger): string
    {
        $trigger = strtolower(trim($trigger));
        $trigger = (string)preg_replace('/[^a-z0-9_-]+/', '-', $trigger);
        $trigger = trim($trigger, '-_');

        if ($trigger === '') {
            return 'manual';
        }

        return substr($trigger, 0, 20);
    }

    public function shouldRunScheduled(): bool
    {
        if (!TestSettings::isScheduleEnabled()) {
            return false;
        }

        $frequency = (string)TestSettings::get('frequency', 'daily');
        $time = $this->validTime((string)TestSettings::get('time_of_day', '03:00'));
        $now = Carbon::now();

        if ($now->format('H:i') < $time) {
            return false;
        }

        return TestSettings::get('last_scheduled_run_key') !== $this->scheduledRunKey($frequency, $now);
    }

    public function markScheduledRun(): void
    {
        $settings = TestSettings::instance();
        $frequency = (string)TestSettings::get('frequency', 'daily');
        $settings->last_scheduled_run_key = $this->scheduledRunKey($frequency, Carbon::now());
        $settings->save();
    }

    public function latestTestUserEvents(): array
    {
        $path = storage_path('logs/posmall-dusk-form-fuzz.log');

        if (!File::exists($path)) {
            return [];
        }

        $lines = array_reverse(explode("\n", (string)File::get($path)));
        $events = [];

        foreach ($lines as $line) {
            if (!str_contains($line, 'dusk.') && !str_contains($line, 'Dusk')) {
                continue;
            }

            $events[] = trim($line);

            if (count($events) >= 20) {
                break;
            }
        }

        return $events;
    }

    private function discoverIn(string $directory, string $type): array
    {
        if (!File::isDirectory($directory)) {
            return [];
        }

        $tests = [];

        foreach (File::allFiles($directory) as $file) {
            if (!str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $path = $this->relativePath($file->getPathname());
            $class = $this->className($file->getPathname());

            $tests[] = [
                'type'    => $type,
                'path'    => $path,
                'class'   => $class,
                'display' => $class ? class_basename($class) : $file->getFilename(),
            ];
        }

        usort($tests, fn (array $a, array $b) => strcmp($a['display'], $b['display']));

        return $tests;
    }

    private function filterSelected(array $tests, array $selected, string $type): array
    {
        if ($selected === [] && !TestSettings::get('selection_initialized', false)) {
            $selected = $this->defaultSelectedPaths($type);
        }

        return array_values(array_filter($tests, fn (array $test) => in_array($test['path'], $selected, true)));
    }

    private function isImportantDefault(array $test): bool
    {
        foreach (['POSMall', 'KodZeroPOSMall', 'PostgreSQL', 'BackendValidation', 'WishlistButton'] as $needle) {
            if (str_contains($test['display'], $needle) || str_contains($test['path'], $needle)) {
                return true;
            }
        }

        return false;
    }

    private function runProcess(TestRun $run, string $logPath, array $test, array $command, string &$errorOutput): bool
    {
        $this->append($logPath, PHP_EOL . '--- ' . $test['display'] . ' ---' . PHP_EOL);
        $this->append($logPath, '$ ' . implode(' ', array_map('escapeshellarg', $command)) . PHP_EOL);

        $process = new Process($command, base_path(), null, null, null);
        $process->setTimeout(null);

        $exitCode = $process->run(function (string $type, string $buffer) use ($logPath, &$errorOutput) {
            $this->append($logPath, $buffer);

            if ($type === Process::ERR) {
                $errorOutput = $this->limitText($errorOutput . $buffer, self::ERROR_LIMIT);
            }
        });

        if ($exitCode !== 0) {
            $errorOutput = $this->limitText(
                $errorOutput
                . PHP_EOL . $test['display'] . ' failed with exit code ' . $exitCode
                . PHP_EOL . $process->getOutput()
                . PHP_EOL . $process->getErrorOutput(),
                self::ERROR_LIMIT
            );
        }

        $run->updated_at = Carbon::now();
        $run->save();

        return $exitCode === 0;
    }

    private function phpunitCommand(array $test): array
    {
        return [base_path('vendor/bin/phpunit'), base_path($test['path'])];
    }

    private function duskCommand(array $test): array
    {
        return [PHP_BINARY, base_path('artisan'), 'dusk', base_path($test['path'])];
    }

    private function prepareLogPath(TestRun $run): string
    {
        $directory = 'kodzero/posmall/test-runs/' . Carbon::now()->format('Y-m-d');
        File::ensureDirectoryExists(storage_path('app/' . $directory));

        return $directory . '/run-' . Carbon::now()->format('His') . '-' . $run->id . '.log';
    }

    private function append(string $logPath, string $text): void
    {
        File::append(storage_path('app/' . $logPath), $text);
    }

    private function className(string $path): ?string
    {
        $contents = (string)File::get($path);
        preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace);
        preg_match('/^class\s+([A-Za-z0-9_]+)/m', $contents, $class);

        if (!isset($class[1])) {
            return null;
        }

        return isset($namespace[1]) ? trim($namespace[1]) . '\\' . $class[1] : $class[1];
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }

    private function sendFailureEmail(TestRun $run): void
    {
        $email = TestSettings::notificationEmail();

        if (!$email || !in_array($run->status, [TestRun::STATUS_FAILED, TestRun::STATUS_ERROR], true)) {
            return;
        }

        Mail::send('kodzero.posmall::mail.tests.failed', [
            'run'           => $run,
            'selectedTests' => $run->selected_tests ?: [],
            'failingTests'  => $run->failing_tests ?: [],
            'errorExcerpt'  => $this->limitText((string)$run->error_output, 4000),
        ], function ($message) use ($email) {
            $message->to($email);
        });
    }

    private function validTime(string $time): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '03:00';
    }

    private function scheduledRunKey(string $frequency, Carbon $now): string
    {
        if ($frequency === 'weekly') {
            return $now->format('o-W');
        }

        if ($frequency === 'monthly') {
            return $now->format('Y-m');
        }

        return $now->format('Y-m-d');
    }

    private function limitText(string $text, int $limit): string
    {
        return strlen($text) > $limit ? substr($text, -$limit) : $text;
    }

    private function duskUserNote(): string
    {
        return implode(PHP_EOL, [
            'Dusk test user note:',
            '- POSMall Dusk tests use deterministic local emails such as dusk.posmall.flow@posmall.test.',
            '- Current POSMall users are persistent/reset between runs, not real inboxes.',
            '- Temporary test records should be deleted or reset by the Dusk test that creates them.',
        ]);
    }
}
