<?php

namespace KodZero\POSMall\Classes\Traits;

use DB;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;

trait StockAndQuantity
{
    public function reduceStock(int $quantity, bool $updateSalesCount = true)
    {
        return DB::transaction(function () use ($quantity, $updateSalesCount) {
            [$lockedProduct, $lockedItem] = $this->lockStockRows();

            if ($lockedItem->allow_out_of_stock_purchases !== true && $lockedItem->stock < $quantity) {
                throw new OutOfStockException($lockedItem);
            }

            $lockedItem->decrement('stock', $quantity);

            if ($updateSalesCount) {
                $lockedItem->increment('sales_count', $quantity);

                if ($lockedItem instanceof Variant) {
                    $lockedProduct->increment('sales_count', $quantity);
                }
            }

            return $this;
        });
    }

    protected function lockStockRows(): array
    {
        $lockedProduct = $this instanceof Variant
            ? Product::whereKey($this->product_id)->lockForUpdate()->firstOrFail()
            : Product::whereKey($this->getKey())->lockForUpdate()->firstOrFail();

        $lockedItem = $this instanceof Variant
            ? Variant::whereKey($this->getKey())->lockForUpdate()->firstOrFail()
            : $lockedProduct;

        return [$lockedProduct, $lockedItem];
    }

    /**
     * Enforce min and max quantity values for a product.
     *
     * @param mixed $quantity
     * @return int
     */
    public function normalizeQuantity($quantity): int
    {
        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($this->quantity_min && $quantity < $this->quantity_min) {
            return $this->quantity_min;
        }

        if ($this->quantity_max && $quantity > $this->quantity_max) {
            return $this->quantity_max;
        }

        return $quantity;
    }

    /**
     * Check if this model is in stock.
     * @return bool
     */
    public function isInStock()
    {
        return $this->allow_out_of_stock_purchases === true || $this->stock > 0;
    }
}
