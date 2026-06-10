<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Support\Facades\Cache;

class ApiCatalogCache
{
    private const VERSION_KEY = 'kodzero.posmall.api.catalog.version';

    public function remember(string $key, int $seconds, callable $callback): array
    {
        if ($seconds < 1) {
            return $callback();
        }

        return Cache::remember($this->versionedKey($key), $seconds, $callback);
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    public function version(): int
    {
        return (int)Cache::rememberForever(self::VERSION_KEY, fn () => 1);
    }

    private function versionedKey(string $key): string
    {
        return 'kodzero.posmall.api.catalog.v' . $this->version() . '.' . $key;
    }
}
