<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use KodZero\POSMall\Models\Order;

class OrderResource
{
    public function order(Order $order): array
    {
        $order->loadMissing(['products', 'payment_method', 'order_state', 'vendor', 'channel', 'warehouse']);

        return [
            'id' => (int)$order->id,
            'hash_id' => $order->hash_id,
            'order_number' => (string)$order->order_number,
            'api_source' => (string)($order->api_source ?: 'web'),
            'payment_state' => (string)$order->payment_state,
            'payment_state_label' => (string)$order->payment_state_label,
            'order_state' => $order->order_state ? [
                'id' => (int)$order->order_state->id,
                'name' => (string)$order->order_state->name,
                'flag' => (string)$order->order_state->flag,
            ] : null,
            'payment_method' => $order->payment_method ? [
                'id' => (int)$order->payment_method->id,
                'name' => (string)$order->payment_method->name,
                'code' => (string)$order->payment_method->code,
            ] : null,
            'items' => $order->products->map(fn ($item) => [
                'id' => (int)$item->id,
                'product_id' => $item->product_id ? (int)$item->product_id : null,
                'variant_id' => $item->variant_id ? (int)$item->variant_id : null,
                'name' => (string)$item->name,
                'variant_name' => (string)$item->variant_name,
                'quantity' => (int)$item->quantity,
                'total_post_taxes' => (int)$item->getOriginal('total_post_taxes'),
            ])->values()->all(),
            'totals' => [
                'product_post_taxes' => (int)$order->getOriginal('total_product_post_taxes'),
                'shipping_post_taxes' => (int)$order->getOriginal('total_shipping_post_taxes'),
                'payment_post_taxes' => (int)$order->getOriginal('total_payment_post_taxes'),
                'taxes' => (int)$order->getOriginal('total_taxes'),
                'post_taxes' => (int)$order->getOriginal('total_post_taxes'),
            ],
            'context' => [
                'vendor' => optional($order->vendor)->only(['id', 'name', 'slug']),
                'channel' => optional($order->channel)->only(['id', 'name', 'slug', 'type']),
                'warehouse' => optional($order->warehouse)->only(['id', 'name', 'slug', 'type']),
            ],
            'created_at' => optional($order->created_at)->toIso8601String(),
        ];
    }
}
