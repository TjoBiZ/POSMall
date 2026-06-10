<?php

namespace KodZero\POSMall\Classes\Observers;

use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Product;

class BrandObserver
{
    protected $index;

    public function __construct(Index $index)
    {
        $this->index = $index;
    }

    public function created(Brand $brand)
    {
    }

    public function updated(Brand $brand)
    {
        $this->handle($brand);
    }

    public function deleted(Brand $brand)
    {
        $this->handle($brand);
    }

    protected function handle(Brand $brand)
    {
        if (! $brand->isDirty('slug')) {
            return;
        }
        $brand->products->each(function (Product $product) {
            if ($product->skipReindex !== true) {
                (new ProductObserver($this->index))->updated($product);
            }
        });
    }
}
