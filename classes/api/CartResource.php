<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CartProduct;
use KodZero\POSMall\Models\Currency;

class CartResource
{
    public function __construct(
        private readonly CatalogImageOptimizer $images
    ) {
    }

    public function cart(Cart $cart): array
    {
        $cart->loadMissing([
            'products.product.image_sets.images',
            'products.variant',
            'products.service_options',
            'discounts',
            'shipping_address',
            'billing_address',
            'shipping_method',
            'payment_method',
        ]);

        return [
            'id' => (int)$cart->id,
            'customer_id' => $cart->customer_id ? (int)$cart->customer_id : null,
            'items' => $cart->products->map(fn (CartProduct $item) => $this->item($item))->values()->all(),
            'items_count' => $cart->products->count(),
            'items_quantity' => (int)$cart->products->sum('quantity'),
            'currency' => Currency::activeCurrency()->code,
            'shipping_address' => $cart->shipping_address?->toArray(),
            'billing_address' => $cart->billing_address?->toArray(),
            'shipping_method' => $cart->shipping_method ? $this->method($cart->shipping_method) : null,
            'payment_method' => $cart->payment_method ? $this->method($cart->payment_method) : null,
            'discounts' => $cart->discounts->map(fn ($discount) => [
                'id' => (int)$discount->id,
                'code' => (string)$discount->code,
                'name' => (string)$discount->name,
            ])->values()->all(),
            'totals' => $this->totals($cart),
        ];
    }

    public function item(CartProduct $item): array
    {
        $product = $item->product;
        $variant = $item->variant;

        return [
            'id' => (int)$item->id,
            'hash_id' => $item->hashid,
            'item_id' => $item->prefixed_id,
            'product_id' => $product?->prefixed_id,
            'variant_id' => $variant?->prefixed_id,
            'name' => (string)($variant?->name ?: $product?->name),
            'product_name' => (string)($product?->name),
            'variant_name' => $variant ? (string)$variant->name : null,
            'quantity' => (int)$item->quantity,
            'image' => $product ? $this->images->catalogSources($variant ?: $product) : null,
            'price' => [
                'integer' => (int)$item->price()->integer,
                'decimal' => (float)$item->price()->decimal,
                'formatted' => (string)$item->price()->string,
            ],
            'total' => [
                'pre_taxes' => (int)$item->total_pre_taxes,
                'taxes' => (int)$item->total_taxes,
                'post_taxes' => (int)$item->total_post_taxes,
            ],
            'service_options' => $item->service_options->map(fn ($option) => [
                'id' => (int)$option->id,
                'name' => (string)$option->name,
                'price' => (string)$option->price()->string,
            ])->values()->all(),
        ];
    }

    public function method($method): array
    {
        return [
            'id' => (int)$method->id,
            'name' => (string)$method->name,
            'code' => (string)($method->code ?? ''),
            'price' => method_exists($method, 'price') ? (string)$method->price()->string : null,
        ];
    }

    private function totals(Cart $cart): array
    {
        $totals = $cart->totals;

        return [
            'product_pre_taxes' => (int)$totals->productPreTaxes(),
            'product_taxes' => (int)$totals->productTaxes(),
            'product_post_taxes' => (int)$totals->productPostTaxes(),
            'shipping_post_taxes' => (int)$totals->shippingTotal()->totalPostTaxes(),
            'payment_post_taxes' => (int)$totals->paymentTotal()->totalPostTaxes(),
            'total_taxes' => (int)$totals->totalTaxes(),
            'total_post_taxes' => (int)$totals->totalPostTaxes(),
        ];
    }
}
