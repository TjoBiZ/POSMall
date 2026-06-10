<?php

namespace KodZero\POSMall\Classes\Traits\Cart;

use DB;
use Illuminate\Support\Collection;
use October\Rain\Support\Facades\Event;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CartProduct;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;

trait CartActions
{
    /**
     * Adds a product to the cart.
     *
     * @param Product $product
     * @param Variant $variant
     * @param int|null $quantity
     * @param Collection $values
     *
     * @return Cart
     */
    public function addProduct(
        Product $product,
        ?int $quantity = null,
        ?Variant $variant = null,
        ?Collection $values = null,
        ?array $serviceOptionIds = [],
        ?bool $serviceOptionsPerQuantity = true
    ) {
        return DB::transaction(function () use ($product, $quantity, $variant, $values, $serviceOptionIds, $serviceOptionsPerQuantity) {

            $response = Event::fire('posmall.cart.product.beforeAdded', [$this, $product, $quantity, $variant, $values, $serviceOptionIds, $serviceOptionsPerQuantity], true);

            if ($response instanceof CartProduct) {
                return $response;
            }

            if (! $this->exists) {
                $this->save();
            }

            $quantity ??= $product->quantity_default ?? 1;

            $price = $variant
                ? $variant->priceIncludingCustomFieldValues($values)
                : $product->priceIncludingCustomFieldValues($values);

            $matchingProductInCart = $this->getMatchingProductInCart($product, $variant, $values);

            $isStackable = $product->stackable && count($serviceOptionIds) === 0 && $matchingProductInCart;

            if ($isStackable) {
                $newQuantity = $product->normalizeQuantity($matchingProductInCart->quantity + $quantity);

                $this->validateStock($variant ?? $product, $quantity);

                // Use current price so a product that is already in the cart does not inherit old price data.
                $matchingProductInCart->attributes['price'] = $matchingProductInCart->mapJsonPrice($price, 1);
                $matchingProductInCart->quantity = $newQuantity;
                $matchingProductInCart->save();

                $this->unsetRelation('products');
                $this->load('products');
                $this->validateShippingMethod();

                return $matchingProductInCart;
            }

            $quantity = $product->normalizeQuantity($quantity);

            $this->validateStock($variant ?? $product, $quantity);

            $cartEntry             = new CartProduct();
            $cartEntry->cart_id    = $this->id;
            $cartEntry->product_id = $product->id;
            $cartEntry->variant_id = $variant ? $variant->id : null;
            $cartEntry->quantity   = $quantity;
            $cartEntry->weight     = $variant ? $variant->weight : $product->weight;
            $cartEntry->service_options_per_quantity = count($serviceOptionIds) > 0 ? $serviceOptionsPerQuantity !== false : true;
            // Skip any setter methods from the JsonPrice trait
            $cartEntry->attributes['price'] = $cartEntry->mapJsonPrice($price, 1);
            
            $this->products()->save($cartEntry);
            $this->load('products');

            if ($values) {
                $cartEntry->custom_field_values()->saveMany($values);
            }

            $this->validateShippingMethod();

            $cartEntry->service_options()->attach($serviceOptionIds);

            return $cartEntry;
        });
    }

    public function removeProduct(CartProduct $product)
    {
        $product->delete();
        $this->validateShippingMethod();

        return $this;
    }

    protected function validateStock($item, $quantity, $ignoreRecord = null)
    {
        $alreadyInCart = $this->getTotalQuantityInCart($item, $ignoreRecord);

        if ($item->allow_out_of_stock_purchases !== true && $item->stock < $quantity + $alreadyInCart) {
            throw new OutOfStockException($item);
        }
    }

    protected function getTotalQuantityInCart($item, $ignoreRecord): int
    {
        $query = CartProduct::where('cart_id', $this->id)
            ->when($ignoreRecord, function ($q) use ($ignoreRecord) {
                $q->where('id', '<>', $ignoreRecord);
            })
            ->when($item instanceof Product, function ($q) use ($item) {
                $q->where('product_id', $item->id);
            })
            ->when($item instanceof Variant, function ($q) use ($item) {
                $q->where('product_id', $item->product_id)
                    ->where('variant_id', $item->id);
            });

        return (int)$query->sum('quantity');
    }
}
