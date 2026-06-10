<?php

namespace KodZero\POSMall\Classes\Jobs;

use Illuminate\Contracts\Queue\Job;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Observers\ProductObserver;
use KodZero\POSMall\Models\Product;

class BrandChangeUpdate
{
    public function fire(Job $job, $data)
    {
        if ($job->attempts() > 5) {
            logger()->error('Failed to handle brand change. Please run php artisan posmall:index manually to update your index');
            $job->delete();
        }

        $index = app(Index::class);

        Product::whereIn('id', $data['ids'] ?? [])
            ->each(function (Product $product) use ($index) {
                (new ProductObserver($index))->updated($product);
            });

        $job->delete();
    }
}
