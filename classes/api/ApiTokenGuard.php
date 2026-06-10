<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\ApiToken;

class ApiTokenGuard
{
    public function authenticate(Request $request, array $requiredScopes = []): ApiToken|JsonResponseMarker
    {
        if (!ApiSettings::enabled()) {
            return JsonResponseMarker::error('api_disabled', 'POSMall API is disabled.', 403);
        }

        $plainToken = $this->plainToken($request);

        if ($plainToken === '') {
            return JsonResponseMarker::error('missing_token', 'API token is required.', 401);
        }

        $token = ApiToken::findUsableByPlainToken($plainToken);
        $hash = ApiToken::hashPlainToken($plainToken);

        if (!$token || !hash_equals((string)$token->token_hash, $hash) || !$token->isUsable()) {
            return JsonResponseMarker::error('invalid_token', 'API token is invalid.', 401);
        }

        if (!$this->allowsOrigin($request, $token)) {
            return JsonResponseMarker::error('origin_not_allowed', 'The request origin is not allowed.', 403);
        }

        if (!$this->hasRequiredScope($token, $requiredScopes)) {
            return JsonResponseMarker::error('insufficient_scope', 'API token scope is not sufficient.', 403);
        }

        $rateLimit = (int)($token->rate_limit_per_minute ?: ApiSettings::defaultRateLimitPerMinute());
        $rateKey = 'kodzero_posmall.api:' . $token->id . ':' . sha1((string)$request->ip());

        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            return JsonResponseMarker::error('rate_limited', 'Too many API requests.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($rateKey),
            ]);
        }

        RateLimiter::hit($rateKey, 60);
        $token->markUsed();

        return $token;
    }

    protected function plainToken(Request $request): string
    {
        $bearer = trim((string)$request->bearerToken());

        if ($bearer !== '') {
            return $bearer;
        }

        return trim((string)$request->headers->get('X-POSMall-API-Key', ''));
    }

    protected function hasRequiredScope(ApiToken $token, array $requiredScopes): bool
    {
        if ($requiredScopes === []) {
            return true;
        }

        foreach ($requiredScopes as $scope) {
            if (!$token->hasScope((string)$scope)) {
                return false;
            }
        }

        return true;
    }

    protected function allowsOrigin(Request $request, ApiToken $token): bool
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
}
