<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\System;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SchedulerCronStatus
{
    protected static ?array $cached = null;

    protected static ?array $recommendedDailyTime = null;

    public function check(): array
    {
        if (static::$cached !== null) {
            return static::$cached;
        }

        $expectedCommand = $this->expectedCommand();

        if (!$this->canRunProcess()) {
            return static::$cached = [
                'state' => 'unknown',
                'label' => 'Cron check unavailable',
                'message' => 'POSMall cannot inspect the server crontab from PHP on this host. Add the daily POSMall tax update cron entry manually and verify it on the server.',
                'expected_command' => $expectedCommand,
            ];
        }

        $crontabBinary = (new ExecutableFinder())->find('crontab');

        if ($crontabBinary === null) {
            return static::$cached = [
                'state' => 'unknown',
                'label' => 'Cron command unavailable',
                'message' => 'The crontab command is not available to this PHP process. Add the daily POSMall tax update cron entry manually on the server.',
                'expected_command' => $expectedCommand,
            ];
        }

        $process = new Process([$crontabBinary, '-l']);
        $process->setTimeout(10);
        $process->run();
        $crontab = $process->getOutput();

        if (trim($crontab) === '') {
            return static::$cached = [
                'state' => 'missing',
                'label' => 'Tax update cron not detected',
                'message' => 'No daily POSMall tax update cron entry was detected for the current system user. Auto-update toggles will not run until cron calls the POSMall tax updater.',
                'expected_command' => $expectedCommand,
            ];
        }

        if (str_contains($crontab, 'artisan posmall:usa-taxes:update') || str_contains($crontab, 'artisan schedule:run')) {
            return static::$cached = [
                'state' => 'detected',
                'label' => 'Tax update cron detected',
                'message' => 'A POSMall tax update or Laravel scheduler cron entry was detected for the current system user. Confirm it points to this POSMall installation and runs during low-traffic hours before relying on automatic tax updates.',
                'expected_command' => $expectedCommand,
            ];
        }

        return static::$cached = [
            'state' => 'missing',
            'label' => 'Tax update cron not detected',
            'message' => 'The current system crontab exists, but POSMall did not find a POSMall tax update or Laravel scheduler entry. Add the daily low-traffic-hour command before relying on automatic tax updates.',
            'expected_command' => $expectedCommand,
        ];
    }

    public function expectedCommand(): string
    {
        return sprintf(
            '%d %d * * * cd %s && php artisan posmall:usa-taxes:update >> /dev/null 2>&1',
            $this->recommendedDailyTime()['minute'],
            $this->recommendedDailyTime()['hour'],
            base_path()
        );
    }

    public function expectedScheduleDescription(): string
    {
        $time = $this->recommendedDailyTime();

        return sprintf(
            'Once per day at %02d:%02d server time. POSMall fixes a different recommendation per installation; 80%% of installations are distributed across the low-traffic 00:00-05:59 window so copied cron entries do not all run at the same minute.',
            $time['hour'],
            $time['minute']
        );
    }

    protected function recommendedDailyTime(): array
    {
        if (static::$recommendedDailyTime !== null) {
            return static::$recommendedDailyTime;
        }

        return static::$recommendedDailyTime = self::recommendedDailyTimeForSeed(
            base_path() . '|' . (string)config('app.key')
        );
    }

    public static function recommendedDailyTimeForSeed(string $seedSource): array
    {
        $seed = abs(crc32($seedSource));
        $minute = (($seed >> 8) % 59) + 1;
        $hourSeed = $seed >> 16;
        $nightWindow = ($hourSeed % 100) < 80;

        return [
            'hour' => $nightWindow
                ? (($hourSeed >> 8) % 6)
                : 6 + (($hourSeed >> 8) % 18),
            'minute' => $minute,
        ];
    }

    public function dailyAt(): string
    {
        $time = $this->recommendedDailyTime();

        return sprintf('%02d:%02d', $time['hour'], $time['minute']);
    }

    protected function canRunProcess(): bool
    {
        if (!class_exists(Process::class)) {
            return false;
        }

        $disabled = array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', (string)ini_get('disable_functions'))
        );

        return !in_array('proc_open', $disabled, true);
    }
}
