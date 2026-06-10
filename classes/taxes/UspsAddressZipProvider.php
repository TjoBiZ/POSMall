<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use KodZero\POSMall\Models\GeneralSettings;
use Throwable;

class UspsAddressZipProvider
{
    public const ENVIRONMENT_PRODUCTION = 'production';
    public const ENVIRONMENT_TESTING = 'testing';

    private const TIMEOUT_SECONDS = 2;
    private const TOKEN_CACHE_PREFIX = 'kodzero_posmall.usps_addresses.token.';
    private const ADDRESS_CACHE_PREFIX = 'kodzero_posmall.usps_addresses.address.';
    private const ADDRESS_FAILURE_CACHE_PREFIX = 'kodzero_posmall.usps_addresses.address.failed.';
    private const CITY_STATE_CACHE_PREFIX = 'kodzero_posmall.usps_addresses.city_state.';

    private const TOKEN_URLS = [
        self::ENVIRONMENT_PRODUCTION => 'https://apis.usps.com/oauth2/v3/token',
        self::ENVIRONMENT_TESTING => 'https://apis-tem.usps.com/oauth2/v3/token',
    ];

    private const ADDRESS_URLS = [
        self::ENVIRONMENT_PRODUCTION => 'https://apis.usps.com/addresses/v3/address',
        self::ENVIRONMENT_TESTING => 'https://apis-tem.usps.com/addresses/v3/address',
    ];

    private const CITY_STATE_URLS = [
        self::ENVIRONMENT_PRODUCTION => 'https://apis.usps.com/addresses/v3/city-state',
        self::ENVIRONMENT_TESTING => 'https://apis-tem.usps.com/addresses/v3/city-state',
    ];

    public static function environmentOptions(): array
    {
        return [
            self::ENVIRONMENT_PRODUCTION => 'Production',
            self::ENVIRONMENT_TESTING => 'Testing / TEM',
        ];
    }

    public function suggest(array $input, string $stateCode, int $limit = 8): array
    {
        if (!$this->isConfigured() || !$this->hasEnoughAddressSignal($input)) {
            return [];
        }

        try {
            $token = $this->token();

            if ($token === null || Cache::has($this->addressFailureCacheKey())) {
                return [];
            }

            $query = $this->addressQuery($input, $stateCode);
            $cacheKey = $this->addressCacheKey($query, $this->environment());

            if (Cache::has($cacheKey)) {
                return array_slice((array)Cache::get($cacheKey), 0, $limit);
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withToken($token)
                ->get($this->addressUrl(), $query);

            if (!$response->successful()) {
                if ($response->status() >= 500 || $response->status() === 429) {
                    $this->rememberAddressFailure();
                }

                return [];
            }

            $suggestions = $this->normalizeAddressResponse((array)$response->json());

            if ($suggestions) {
                Cache::put($cacheKey, $suggestions, 900);
            }

            return array_slice($suggestions, 0, $limit);
        } catch (ConnectionException $e) {
            $this->rememberAddressFailure();

            return [];
        } catch (Throwable $e) {
            $this->rememberAddressFailure();

            return [];
        }
    }

    public function suggestCityStateByZip(string $zip): array
    {
        $zip = $this->normalizeZipPrefix($zip);

        if (strlen($zip) !== 5 || !$this->isConfigured()) {
            return [];
        }

        $cacheKey = $this->cityStateCacheKey($zip, $this->environment());

        if (Cache::has($cacheKey)) {
            return (array)Cache::get($cacheKey);
        }

        try {
            $token = $this->token();

            if ($token === null || Cache::has($this->addressFailureCacheKey())) {
                return [];
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withToken($token)
                ->get($this->cityStateUrl(), ['ZIPCode' => $zip]);

            if (!$response->successful()) {
                if ($response->status() >= 500 || $response->status() === 429) {
                    $this->rememberAddressFailure();
                }

                return [];
            }

            $suggestions = $this->normalizeCityStateResponse((array)$response->json(), $zip);

            if ($suggestions) {
                Cache::put($cacheKey, $suggestions, 86400);
            }

            return $suggestions;
        } catch (ConnectionException $e) {
            $this->rememberAddressFailure();

            return [];
        } catch (Throwable $e) {
            $this->rememberAddressFailure();

            return [];
        }
    }

    public function isConfigured(): bool
    {
        return (bool)GeneralSettings::get('usps_addresses_enabled')
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    public function configuredClientId(): string
    {
        return $this->clientId();
    }

    public function hasConfiguredClientSecret(): bool
    {
        return $this->clientSecret() !== '';
    }

    public function environment(): string
    {
        $environment = (string)GeneralSettings::get('usps_addresses_environment', self::ENVIRONMENT_PRODUCTION);

        return array_key_exists($environment, self::TOKEN_URLS)
            ? $environment
            : self::ENVIRONMENT_PRODUCTION;
    }

    public function clearTokenCache(): void
    {
        $clientId = $this->clientId();

        if ($clientId !== '') {
            Cache::forget($this->tokenCacheKey($clientId, $this->environment()));
            Cache::forget($this->tokenFailureCacheKey($clientId, $this->environment()));
        }

        Cache::forget($this->addressFailureCacheKey());
    }

    private function token(): ?string
    {
        $clientId = $this->clientId();
        $clientSecret = $this->clientSecret();

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cacheKey = $this->tokenCacheKey($clientId, $this->environment());
        $failureKey = $this->tokenFailureCacheKey($clientId, $this->environment());

        if (Cache::has($cacheKey)) {
            return (string)Cache::get($cacheKey);
        }

        if (Cache::has($failureKey)) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post($this->tokenUrl(), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (!$response->successful()) {
                Cache::put($failureKey, true, 60);

                return null;
            }

            $token = trim((string)$response->json('access_token', ''));
            $expiresIn = (int)$response->json('expires_in', 300);

            if ($token === '') {
                Cache::put($failureKey, true, 60);

                return null;
            }

            Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

            return $token;
        } catch (ConnectionException $e) {
            Cache::put($failureKey, true, 60);

            return null;
        } catch (Throwable $e) {
            Cache::put($failureKey, true, 60);

            return null;
        }
    }

    private function tokenCacheKey(string $clientId, string $environment): string
    {
        return self::TOKEN_CACHE_PREFIX . sha1($environment . '|' . $clientId);
    }

    private function tokenFailureCacheKey(string $clientId, string $environment): string
    {
        return $this->tokenCacheKey($clientId, $environment) . '.failed';
    }

    private function cityStateCacheKey(string $zip, string $environment): string
    {
        return self::CITY_STATE_CACHE_PREFIX . sha1($environment . '|' . $zip);
    }

    private function addressCacheKey(array $query, string $environment): string
    {
        ksort($query);

        return self::ADDRESS_CACHE_PREFIX . sha1($environment . '|' . json_encode($query));
    }

    private function addressFailureCacheKey(): string
    {
        return self::ADDRESS_FAILURE_CACHE_PREFIX . $this->environment();
    }

    private function rememberAddressFailure(): void
    {
        Cache::put($this->addressFailureCacheKey(), true, 60);
    }

    private function tokenUrl(): string
    {
        return self::TOKEN_URLS[$this->environment()];
    }

    private function addressUrl(): string
    {
        return self::ADDRESS_URLS[$this->environment()];
    }

    private function cityStateUrl(): string
    {
        return self::CITY_STATE_URLS[$this->environment()];
    }

    private function addressQuery(array $input, string $stateCode): array
    {
        return array_filter([
            'streetAddress' => $this->line($input),
            'city' => trim((string)($input['city'] ?? '')),
            'state' => $stateCode,
            'ZIPCode' => $this->normalizeZipPrefix($input['zip'] ?? ''),
        ], fn ($value) => $value !== '');
    }

    private function normalizeAddressResponse(array $payload): array
    {
        $address = (array)($payload['address'] ?? $payload);
        $zip = $this->normalizeZipPrefix($address['ZIPCode'] ?? $address['zipCode'] ?? '');
        $zipPlus4 = preg_replace('/\D+/', '', (string)($address['ZIPPlus4'] ?? $address['zipPlus4'] ?? ''));

        if ($zip === '') {
            return [];
        }

        $value = strlen($zipPlus4) === 4 ? $zip . '-' . $zipPlus4 : $zip;
        $city = trim((string)($address['city'] ?? ''));
        $state = trim((string)($address['state'] ?? ''));

        return [[
            'zip' => $value,
            'label' => trim($value . ($city || $state ? ' - ' . trim($city . ', ' . $state, ', ') : '')),
            'lines' => trim((string)($address['streetAddress'] ?? '')),
            'details' => trim((string)($address['secondaryAddress'] ?? '')),
            'city' => $city,
            'country_code' => 'US',
            'state_code' => $state,
            'source' => 'usps',
        ]];
    }

    private function normalizeCityStateResponse(array $payload, string $requestedZip): array
    {
        $address = (array)($payload['address'] ?? $payload);
        $zip = $this->normalizeZipPrefix($address['ZIPCode'] ?? $address['zipCode'] ?? $requestedZip);
        $city = trim((string)($address['city'] ?? $address['cityName'] ?? ''));
        $state = strtoupper(trim((string)($address['state'] ?? $address['stateCode'] ?? '')));

        if ($zip === '' || $city === '' || !preg_match('/^[A-Z]{2}$/', $state)) {
            return [];
        }

        return [[
            'zip' => $zip,
            'label' => trim($zip . ' - ' . $city . ', ' . $state),
            'city' => $city,
            'country_code' => 'US',
            'state_code' => $state,
            'source' => 'usps_city_state',
        ]];
    }

    private function hasEnoughAddressSignal(array $input): bool
    {
        $line = $this->line($input);
        $city = trim((string)($input['city'] ?? ''));
        $zip = $this->normalizeZipPrefix($input['zip'] ?? '');

        return mb_strlen($line) >= 6
            && (mb_strlen($city) >= 3 || strlen($zip) === 5);
    }

    private function line(array $input): string
    {
        return trim(preg_replace('/\s+/', ' ', (string)($input['lines'] ?? '')));
    }

    private function normalizeZipPrefix($value): string
    {
        return substr(preg_replace('/\D+/', '', (string)$value), 0, 5);
    }

    private function clientId(): string
    {
        return $this->decryptSetting(GeneralSettings::get('usps_addresses_client_id'));
    }

    private function clientSecret(): string
    {
        return $this->decryptSetting(GeneralSettings::get('usps_addresses_client_secret'));
    }

    private function decryptSetting($value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        try {
            return trim((string)decrypt($value));
        } catch (Throwable $e) {
            return $value;
        }
    }
}
