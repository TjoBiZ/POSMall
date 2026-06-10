<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use KodZero\POSMall\Classes\Security\BackendAdminSafety;
use KodZero\POSMall\Classes\Tests\TestRunner;

class POSMallTestsRun extends Command
{
    protected $signature = 'posmall:tests:run
        {--all : Run every discovered test}
        {--scheduled : Run only when the saved schedule is due}
        {--trigger=manual : Run trigger label}
        {--backend-user-id= : Backend user id for manual runs}
        {--include-backend : Include legacy backend PHPUnit tests. Use only with an isolated test database.}
        {--force : Allow running outside local/dev/testing environments}';

    protected $description = 'Run selected POSMall backend and Dusk tests';

    public function handle(TestRunner $runner): int
    {
        if (! $this->canRunInCurrentEnvironment()) {
            $this->error('POSMall tests are disabled outside local/dev/testing environments. Use --force only in a backed-up safe environment.');

            return 1;
        }

        if (!Schema::hasTable('kodzero_posmall_test_runs')) {
            $this->error('The kodzero_posmall_test_runs table is missing. Run October updates before starting tests.');

            return 1;
        }

        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:tests:run before broad test execution');

        if ($this->option('scheduled')) {
            if (!$runner->shouldRunScheduled()) {
                $this->line('No scheduled POSMall test run is due.');

                return 0;
            }

            $runner->markScheduledRun();
        }

        $tests = $runner->selectedTests((bool)$this->option('all'));

        if (!$this->option('include-backend') && $this->usesPersistentDatabase()) {
            $backendCount = count($tests['backend'] ?? []);
            $tests['backend'] = [];

            if ($backendCount > 0) {
                $this->warn(sprintf(
                    'Skipped %d backend PHPUnit tests because the current database is persistent. Use --include-backend only with an isolated test database.',
                    $backendCount
                ));
            }
        }

        $run = $runner->run(
            $tests,
            (string)$this->option('trigger'),
            $this->option('backend-user-id') ? (int)$this->option('backend-user-id') : null
        );

        $this->line(sprintf('POSMall test run #%d finished with status: %s', $run->id, $run->status));
        $this->line('Log: storage/app/' . $run->log_path);

        return $run->status === 'passed' ? 0 : 1;
    }

    protected function canRunInCurrentEnvironment(): bool
    {
        return $this->option('force')
            || app()->environment(['local', 'dev', 'development', 'testing']);
    }

    protected function usesPersistentDatabase(): bool
    {
        $connection = config('database.default');
        $driver = (string)config('database.connections.' . $connection . '.driver');
        $database = (string)config('database.connections.' . $connection . '.database');

        return !($driver === 'sqlite' && $database === ':memory:');
    }
}
