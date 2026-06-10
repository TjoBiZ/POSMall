<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Support\Collection;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;

class ProductResource
{
    public function __construct(
        private readonly CatalogImageOptimizer $images
    ) {
    }

    public function listingItem(Product|Variant $item): array
    {
        $product = $item instanceof Variant ? $item->product : $item;
        $variant = $item instanceof Variant ? $item : null;
        $price = $item->price(Currency::activeCurrency());

        return array_filter([
            'id' => $item->prefixed_id,
            'hash_id' => $item->prefixed_hash_id,
            'type' => $variant ? 'variant' : ($product->is_virtual ? 'virtual_product' : 'product'),
            'product_id' => $product->prefixed_id,
            'product_hash_id' => $product->prefixed_hash_id,
            'variant_id' => $variant?->prefixed_id,
            'variant_hash_id' => $variant?->prefixed_hash_id,
            'name' => (string)$item->name,
            'product_name' => (string)$product->name,
            'variant_name' => $variant ? (string)$variant->name : null,
            'slug' => (string)$product->slug,
            'sku' => (string)($item->user_defined_id ?: $product->user_defined_id),
            'description_short' => (string)($item->description_short ?: $product->description_short),
            'price' => [
                'currency' => Currency::activeCurrency()->code,
                'integer' => (int)$price->integer,
                'decimal' => (float)$price->decimal,
                'formatted' => (string)$price,
            ],
            'stock' => (int)$item->stock,
            'rating' => (float)$item->reviews_rating,
            'image' => $this->images->catalogSources($item),
            'flags' => [
                'published' => (bool)$item->published,
                'virtual' => (bool)$product->is_virtual,
                'shippable' => (bool)$product->shippable,
                'stackable' => (bool)$product->stackable,
                'on_sale' => (bool)$item->on_sale,
            ],
        ], fn ($value) => $value !== null);
    }

    public function detail(Product $product): array
    {
        $product->loadMissing([
            'brand',
            'categories',
            'image_sets.images',
            'prices.currency',
            'variants.prices.currency',
            'variants.image_sets.images',
            'property_values.property',
            'variants.property_values.property',
            'services.options.prices.currency',
        ]);

        return [
            'item' => $this->listingItem($product),
            'brand' => $product->brand ? [
                'id' => (int)$product->brand->id,
                'name' => (string)$product->brand->name,
                'slug' => (string)$product->brand->slug,
            ] : null,
            'categories' => $product->categories->map(fn ($category) => [
                'id' => (int)$category->id,
                'name' => (string)$category->name,
                'slug' => (string)$category->nested_slug,
            ])->values()->all(),
            'description' => (string)$product->description,
            'images' => Collection::make($product->all_images)->filter()->map(
                fn ($image) => $this->images->imageSources($image, (string)$product->name, CatalogImageOptimizer::PROFILE_PRODUCT)
            )->filter()->values()->all(),
            'properties' => $product->property_values->map(fn ($value) => [
                'id' => (int)$value->id,
                'property_id' => (int)$value->property_id,
                'property' => (string)optional($value->property)->name,
                'value' => $value->value,
                'display_value' => (string)$value->display_value,
            ])->values()->all(),
            'variants' => $product->variants
                ->where('published', true)
                ->map(fn (Variant $variant) => $this->listingItem($variant))
                ->values()
                ->all(),
            'services' => $product->services->map(fn ($service) => [
                'id' => (int)$service->id,
                'name' => (string)$service->name,
                'code' => (string)$service->code,
                'required' => (bool)($service->pivot->required ?? false),
                'options' => $service->options->map(fn ($option) => [
                    'id' => (int)$option->id,
                    'name' => (string)$option->name,
                    'price' => (string)$option->price()->string,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
