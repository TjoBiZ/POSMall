<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Model;
use Throwable;

class ApiSettings extends Model
{
    public const SETTINGS_CODE = 'kodzero_posmall_api_settings';
    public const SETTINGS_CACHE_KEY = 'system::settings.kodzero_posmall_api_settings';

    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = self::SETTINGS_CODE;

    public $settingsFields = '$/kodzero/posmall/models/settings/fields_api.yaml';

    public function initSettingsData()
    {
        $this->api_enabled = false;
        $this->default_rate_limit_per_minute = 60;
        $this->payment_link_expiry_minutes = 1440;
        $this->allowed_origins = '';
        $this->api_catalog_cache_seconds = 30;
        $this->route_access_rules = '';
        $this->route_access_password_hash = '';
        $this->route_access_password = '';
        $this->graphql_enabled = false;
        $this->graphql_introspection_enabled = false;
        $this->graphql_max_depth = 8;
        $this->graphql_max_complexity = 120;
    }

    public function afterSave()
    {
        Cache::forget(self::SETTINGS_CACHE_KEY);
    }

    public function beforeSave(): void
    {
        $plainPassword = trim((string)($this->route_access_password ?? ''));

        if ($plainPassword !== '') {
            $this->route_access_password_hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        }

        unset($this->attributes['route_access_password']);
    }

    public static function enabled(): bool
    {
        return (bool)static::runtimeValue('api_enabled', false);
    }

    public static function defaultRateLimitPerMinute(): int
    {
        return max(1, (int)static::runtimeValue('default_rate_limit_per_minute', 60));
    }

    public static function paymentLinkExpiryMinutes(): int
    {
        return max(5, (int)static::runtimeValue('payment_link_expiry_minutes', 1440));
    }

    public static function catalogCacheSeconds(): int
    {
        return max(0, (int)static::runtimeValue('api_catalog_cache_seconds', 30));
    }

    public static function routeAccessRules(): array
    {
        $lines = preg_split('/\R+/', (string)static::runtimeValue('route_access_rules', '')) ?: [];
        $rules = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];

            if (count($parts) < 4) {
                continue;
            }

            [$method, $path, $mode, $tokenId] = array_slice($parts, 0, 4);
            $mode = strtolower((string)$mode);

            if (!in_array($mode, ['public', 'password'], true)) {
                continue;
            }

            $rules[] = [
                'method' => strtoupper((string)$method),
                'path' => '/' . ltrim((string)$path, '/'),
                'mode' => $mode,
                'token_id' => (int)$tokenId,
            ];
        }

        return $rules;
    }

    public static function routeAccessPasswordHash(): string
    {
        return (string)static::runtimeValue('route_access_password_hash', '');
    }

    public static function allowedOrigins(): array
    {
        $lines = preg_split('/\R+/', (string)static::runtimeValue('allowed_origins', '')) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }

    public static function graphqlEnabled(): bool
    {
        return (bool)static::runtimeValue('graphql_enabled', false);
    }

    public static function graphqlIntrospectionEnabled(): bool
    {
        return (bool)static::runtimeValue('graphql_introspection_enabled', false);
    }

    public static function graphqlMaxDepth(): int
    {
        return max(1, (int)static::runtimeValue('graphql_max_depth', 8));
    }

    public static function graphqlMaxComplexity(): int
    {
        return max(10, (int)static::runtimeValue('graphql_max_complexity', 120));
    }

    protected static function runtimeValue(string $key, mixed $default = null): mixed
    {
        try {
            $value = DB::table('system_settings')
                ->where('item', self::SETTINGS_CODE)
                ->value('value');

            if (!$value) {
                return static::get($key, $default);
            }

            $settings = is_array($value) ? $value : json_decode((string)$value, true);

            if (!is_array($settings)) {
                return static::get($key, $default);
            }

            return array_key_exists($key, $settings) ? $settings[$key] : $default;
        } catch (Throwable) {
            return static::get($key, $default);
        }
    }
}
