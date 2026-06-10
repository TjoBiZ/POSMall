<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\ApiToken;

class ApiRouteAccessGuard
{
    public function authenticate(Request $request, array $requiredScopes = []): ApiToken|JsonResponseMarker|null
    {
        $rule = $this->matchingRule($request);

        if ($rule === null) {
            return null;
        }

        $token = ApiToken::query()
            ->whereNull('revoked_at')
            ->whereKey((int)$rule['token_id'])
            ->first();

        if (!$token || !$token->isUsable()) {
            return JsonResponseMarker::error('route_access_token_invalid', 'Configured route access token is invalid.', 403);
        }

        if ($rule['mode'] === 'password' && !$this->passwordMatches($request)) {
            $this->hitRateLimit($request, $token);

            return JsonResponseMarker::error('route_access_password_required', 'Route access password is required.', 401);
        }

        if (!$this->allowsOrigin($request, $token)) {
            return JsonResponseMarker::error('origin_not_allowed', 'The request origin is not allowed.', 403);
        }

        if (!$this->hasRequiredScope($token, $requiredScopes)) {
            return JsonResponseMarker::error('insufficient_scope', 'API token scope is not sufficient.', 403);
        }

        $rateLimited = $this->enforceRateLimit($request, $token);
        if ($rateLimited instanceof JsonResponseMarker) {
            return $rateLimited;
        }

        $token->markUsed();

        return $token;
    }

    private function matchingRule(Request $request): ?array
    {
        $method = strtoupper($request->method());
        $path = '/' . ltrim($request->path(), '/');

        foreach (ApiSettings::routeAccessRules() as $rule) {
            if (!$this->methodMatches($method, (string)$rule['method'])) {
                continue;
            }

            if (!$this->pathMatches($path, (string)$rule['path'])) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    private function methodMatches(string $actual, string $expected): bool
    {
        return $expected === '*' || strtoupper($expected) === $actual;
    }

    private function pathMatches(string $actual, string $expected): bool
    {
        if (str_ends_with($expected, '*')) {
            return str_starts_with($actual, rtrim($expected, '*'));
        }

        return $actual === $expected;
    }

    private function passwordMatches(Request $request): bool
    {
        $hash = ApiSettings::routeAccessPasswordHash();

        if ($hash === '') {
            return false;
        }

        $password = trim((string)(
            $request->headers->get('X-POSMall-Access-Password')
            ?: $request->query('access_password')
            ?: $request->request->get('access_password')
            ?: $request->json('access_password')
        ));

        return $password !== '' && password_verify($password, $hash);
    }

    private function hasRequiredScope(ApiToken $token, array $requiredScopes): bool
    {
        foreach ($requiredScopes as $scope) {
            if (!$token->hasScope((string)$scope)) {
                return false;
            }
        }

        return true;
    }

    private function allowsOrigin(Request $request, ApiToken $token): bool
    {
        $origin = $request->headers->get('Origin');
        $globalOrigins = ApiSettings::allowedOrigins();

        if ($globalOrigins !== []) {
            if (!is_string($origin) || !in_array(trim($origin), $globalOrigins, true)) {
                return false;
            }
        }

        return $token->allowsOrigin($origin);
    }

    private function enforceRateLimit(Request $request, ApiToken $token): ?JsonResponseMarker
    {
        $rateLimit = (int)($token->rate_limit_per_minute ?: ApiSettings::defaultRateLimitPerMinute());
        $rateKey = $this->rateKey($request, $token);

        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            return JsonResponseMarker::error('rate_limited', 'Too many API requests.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($rateKey),
            ]);
        }

        RateLimiter::hit($rateKey, 60);

        return null;
    }

    private function hitRateLimit(Request $request, ApiToken $token): void
    {
        RateLimiter::hit($this->rateKey($request, $token), 60);
    }

    private function rateKey(Request $request, ApiToken $token): string
    {
        return 'kodzero_posmall.route_access:' . $token->id . ':' . sha1((string)$request->ip());
    }
}
