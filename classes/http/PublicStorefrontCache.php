<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Http;

use App;
use Cache;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use KodZero\POSMall\Classes\PageSpeed\StorefrontAssetOptimizer;
use Throwable;

class PublicStorefrontCache
{
    private const TTL_SECONDS = 60;
    private const VERSION_KEY = 'kodzero.posmall.public_storefront.version';

    private const PREFLIGHT_HIT_ATTRIBUTE = '__posmall_public_cache_preflight_hit';
    private const PREFLIGHT_CONTENT_ATTRIBUTE = '__posmall_public_cache_preflight_content';

    private static ?string $storeName = null;

    private const CACHEABLE_PREFIXES = [
        'posmall/catalog',
        'posmall/category',
        'posmall/search',
    ];

    private const PERSONAL_COOKIE_MARKERS = [
        'cart_session_id',
        'wishlist_session_id',
        'remember_web',
    ];

    public static function bumpVersion(): void
    {
        try {
            Cache::store((string)config('cache.default', 'file'))->forever(self::VERSION_KEY, microtime(true));
        } catch (Throwable) {
            Cache::forever(self::VERSION_KEY, microtime(true));
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(self::PREFLIGHT_HIT_ATTRIBUTE) === true) {
            $cached = $request->attributes->get(self::PREFLIGHT_CONTENT_ATTRIBUTE);

            if (is_string($cached) && $cached !== '') {
                return response($cached, 200, $this->cacheHitHeaders($cached) + [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'X-POSMall-Public-Cache' => 'hit',
                ]);
            }
        }

        if (!$this->isEligibleRequest($request)) {
            return $this->mark($next($request), 'bypass');
        }

        $key = $this->cacheKey($request);
        if ($cached = $this->cacheGet($key)) {
            return response($cached, 200, $this->cacheHitHeaders($cached) + [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-POSMall-Public-Cache' => 'hit',
            ]);
        }

        $response = $next($request);
        if ($this->isCacheableResponse($response)) {
            $this->cachePut($key, (string)$response->getContent());
            $this->removeAnonymousSessionCookie($response);
        }

        return $this->mark($response, 'miss');
    }

    public function storePreflightHit(Request $request): bool
    {
        if (!$this->isEligibleRequest($request)) {
            return false;
        }

        $cached = $this->cacheGet($this->cacheKey($request));
        if (!is_string($cached) || $cached === '') {
            return false;
        }

        $request->attributes->set(self::PREFLIGHT_HIT_ATTRIBUTE, true);
        $request->attributes->set(self::PREFLIGHT_CONTENT_ATTRIBUTE, $cached);

        return true;
    }

    private function isEligibleRequest(Request $request): bool
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        if ($request->ajax() || $request->headers->has('X-OCTOBER-REQUEST-HANDLER')) {
            return false;
        }

        if ($request->query->has('posmall_currency')) {
            return false;
        }

        if (!$this->isCacheablePath($request)) {
            return false;
        }

        return !$this->hasPersonalCookie($request);
    }

    private function isCacheablePath(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach (self::CACHEABLE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function hasPersonalCookie(Request $request): bool
    {
        $cookieHeader = (string)$request->headers->get('cookie', '');
        if ($cookieHeader === '') {
            return false;
        }

        $sessionCookie = (string)config('session.cookie', 'october_session');
        $markers = array_filter(array_merge([$sessionCookie], self::PERSONAL_COOKIE_MARKERS));

        foreach ($markers as $marker) {
            if (str_contains($cookieHeader, $marker . '=')) {
                return true;
            }
        }

        return false;
    }

    private function isCacheableResponse(Response $response): bool
    {
        if (!$response->isSuccessful()) {
            return false;
        }

        $contentType = (string)$response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }

        if (!method_exists($response, 'getContent')) {
            return false;
        }

        $content = (string)$response->getContent();

        return !str_contains($content, 'name="_token"')
            && !str_contains($content, "name='_token'")
            && !str_contains($content, 'name="_session_key"')
            && !str_contains($content, "name='_session_key'");
    }

    private function cacheKey(Request $request): string
    {
        return 'kodzero.posmall.public_storefront.'
            . md5(
                $request->getSchemeAndHttpHost()
                . '|' . $request->fullUrl()
                . '|' . App::getLocale()
                . '|' . $this->assetVariant()
                . '|' . $this->contentVersion()
            );
    }

    private function contentVersion(): string
    {
        try {
            return (string)$this->cacheStore()->get(self::VERSION_KEY, '1');
        } catch (Throwable) {
            return '1';
        }
    }

    private function assetVariant(): string
    {
        try {
            $optimizer = app(StorefrontAssetOptimizer::class);

            return implode('|', [
                $optimizer->optimizedAssetsEnabled() ? 'optimized' : 'source',
                $optimizer->assetPath('css'),
                $optimizer->assetVersion('css'),
                $optimizer->assetPath('js'),
                $optimizer->assetVersion('js'),
            ]);
        } catch (Throwable) {
            return 'assets:unknown';
        }
    }

    private function cacheGet(string $key): ?string
    {
        try {
            $cached = $this->cacheStore()->get($key);
        } catch (Throwable) {
            $cached = $this->fallbackCacheStore()->get($key);
        }

        return is_string($cached) && $cached !== '' ? $cached : null;
    }

    private function cachePut(string $key, string $content): void
    {
        try {
            $this->cacheStore()->put($key, $content, self::TTL_SECONDS);
        } catch (Throwable) {
            $this->fallbackCacheStore()->put($key, $content, self::TTL_SECONDS);
        }
    }

    private function cacheHitHeaders(string $content): array
    {
        return [
            'Cache-Control' => 'public, max-age=' . self::TTL_SECONDS . ', s-maxage=' . self::TTL_SECONDS,
            'Content-Length' => (string)strlen($content),
            'Vary' => 'Accept-Encoding, Cookie',
        ];
    }

    private function cacheStore(): CacheRepository
    {
        if (self::$storeName === null) {
            self::$storeName = (string)config('cache.default', 'file');
        }

        return Cache::store(self::$storeName);
    }

    private function fallbackCacheStore(): CacheRepository
    {
        self::$storeName = (string)config('cache.default', 'file');

        return Cache::store(self::$storeName);
    }

    private function mark(Response $response, string $state): Response
    {
        $response->headers->set('X-POSMall-Public-Cache', $state);

        return $response;
    }

    private function removeAnonymousSessionCookie(Response $response): void
    {
        $sessionCookie = (string)config('session.cookie', 'october_session');
        if ($sessionCookie === '') {
            return;
        }

        $response->headers->removeCookie($sessionCookie);
    }
}
