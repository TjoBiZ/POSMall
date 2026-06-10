<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Support\Facades\URL;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\Order;

class PaymentLinkService
{
    public const TOKEN_PREFIX = 'SMSPay_';

    public function create(Order $order): array
    {
        $plainToken = self::TOKEN_PREFIX . bin2hex(random_bytes(16));
        $order->payment_link_token_hash = $this->hash($plainToken);
        $order->payment_link_expires_at = now()->addMinutes(ApiSettings::paymentLinkExpiryMinutes());
        $order->payment_link_used_at = null;
        $order->save();

        return [
            'url' => URL::to('/posmall/pay/' . $plainToken),
            'expires_at' => optional($order->payment_link_expires_at)->toIso8601String(),
            'reused' => false,
        ];
    }

    public function reuseOrCreate(Order $order): array
    {
        if ($this->hasActiveLink($order)) {
            return [
                'url' => null,
                'expires_at' => optional($order->payment_link_expires_at)->toIso8601String(),
                'reused' => true,
                'note' => 'An active payment link already exists. POSMall does not return existing bearer links again.',
            ];
        }

        return $this->create($order);
    }

    public function resolve(string $token): Order|JsonResponseMarker
    {
        $order = Order::where('payment_link_token_hash', $this->hash($token))
            ->where('payment_link_expires_at', '>', now())
            ->first();

        if (!$order) {
            return JsonResponseMarker::error('payment_link_not_found', 'Payment link is invalid or expired.', 404);
        }

        if ($order->payment_state === PaidState::class) {
            return JsonResponseMarker::error('payment_link_paid', 'This order is already paid.', 409);
        }

        if ($order->payment_link_used_at === null) {
            $order->payment_link_used_at = now();
            $order->save();
        }

        return $order;
    }

    private function hasActiveLink(Order $order): bool
    {
        return $order->payment_link_token_hash !== null
            && $order->payment_link_expires_at !== null
            && $order->payment_link_expires_at > now()
            && $order->payment_state !== PaidState::class;
    }

    private function hash(string $token): string
    {
        return hash('sha256', trim($token));
    }
}
