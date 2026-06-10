<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class TestSettings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'kodzero_posmall_test_settings';

    public $settingsFields = '$/kodzero/posmall/models/testsettings/fields.yaml';

    public static function isScheduleEnabled(): bool
    {
        return (bool)self::get('schedule_enabled', false)
            && self::get('frequency', 'daily') !== 'disabled';
    }

    public static function selectedBackendTests(): array
    {
        return self::arrayValue('selected_backend_tests');
    }

    public static function selectedDuskTests(): array
    {
        return self::arrayValue('selected_dusk_tests');
    }

    public static function notifyOnlyOnFailure(): bool
    {
        return (bool)self::get('notify_only_on_failure', true);
    }

    public static function notificationEmail(): ?string
    {
        $email = trim((string)self::get('failure_email', ''));

        return $email !== '' ? $email : null;
    }

    private static function arrayValue(string $key): array
    {
        $value = self::get($key, []);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
