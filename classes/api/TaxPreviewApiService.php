<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

class TaxPreviewApiService
{
    public function __construct(
        private readonly CartApiService $carts
    ) {
    }

    public function cart(array $input, ?CommerceContext $context = null): array
    {
        $cart = $this->carts->get($input, $context)['cart'];
        $totals = $cart['totals'] ?? [];

        return [
            'tax_preview' => [
                'cart_id' => $cart['id'] ?? null,
                'customer_id' => $cart['customer_id'] ?? null,
                'currency' => $cart['currency'] ?? null,
                'product_taxes' => $totals['product_taxes'] ?? null,
                'total_taxes' => $totals['total_taxes'] ?? null,
                'product_pre_taxes' => $totals['product_pre_taxes'] ?? null,
                'product_post_taxes' => $totals['product_post_taxes'] ?? null,
                'shipping_post_taxes' => $totals['shipping_post_taxes'] ?? null,
                'payment_post_taxes' => $totals['payment_post_taxes'] ?? null,
                'total_post_taxes' => $totals['total_post_taxes'] ?? null,
            ],
        ];
    }
}
