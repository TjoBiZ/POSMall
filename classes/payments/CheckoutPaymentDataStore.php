<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Payments;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\PaymentMethod;

class CheckoutPaymentDataStore
{
    private const SESSION_KEY = 'posmall.payment_method.data';
    private const CACHE_PREFIX = 'kodzero_posmall.checkout_payment_data.';
    private const TTL_MINUTES = 30;

    public function rememberForCart(Cart $cart, PaymentMethod $method, array $data): void
    {
        $payload = encrypt(json_encode([
            'payment_method_id' => (int)$method->id,
            'payment_provider' => (string)$method->payment_provider,
            'data' => $this->safeDataForProvider((string)$method->payment_provider, $data),
        ], JSON_THROW_ON_ERROR));

        session()->put(self::SESSION_KEY, $payload);
        Cache::put($this->cacheKey($cart), $payload, now()->addMinutes(self::TTL_MINUTES));
    }

    public function getForCart(Cart $cart, ?PaymentMethod $method = null): array
    {
        $sessionData = $this->decodePayload(session()->get(self::SESSION_KEY));

        if (! empty($sessionData) && $this->matchesMethod($sessionData, $method)) {
            return $sessionData['data'] ?? [];
        }

        $cacheData = $this->decodePayload(Cache::get($this->cacheKey($cart)));

        if (! empty($cacheData) && $this->matchesMethod($cacheData, $method)) {
            return $cacheData['data'] ?? [];
        }

        return [];
    }

    public function forgetForCart(?Cart $cart = null): void
    {
        if ($cart) {
            Cache::forget($this->cacheKey($cart));
        }

        session()->forget(self::SESSION_KEY);
    }

    private function decodePayload($payload): array
    {
        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode(decrypt($payload), true);
        } catch (DecryptException $e) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function matchesMethod(array $payload, ?PaymentMethod $method): bool
    {
        if (! $method) {
            return true;
        }

        return (int)($payload['payment_method_id'] ?? 0) === (int)$method->id
            && (string)($payload['payment_provider'] ?? '') === (string)$method->payment_provider;
    }

    private function safeDataForProvider(string $provider, array $data): array
    {
        $allowed = match ($provider) {
            'stripe' => ['token', 'use_customer_payment_method'],
            default => ['use_customer_payment_method'],
        };

        return collect($data)
            ->only($allowed)
            ->filter(fn ($value) => is_scalar($value) || $value === null)
            ->all();
    }

    private function cacheKey(Cart $cart): string
    {
        return self::CACHE_PREFIX . implode('.', [
            (int)$cart->id,
            (int)$cart->customer_id,
            hash('sha256', (string)session()->getId()),
        ]);
    }
}
