<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use Illuminate\Support\Facades\Artisan;
use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\Product;

class ProductTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if (! $useDemo) {
            if (config('app.env') == 'testing') {
                $product = Product::create([
                    'name' => 'Test',
                    'slug' => 'test',
                    'stock' => 20,
                    'published' => true,
                ]);
                $product->price = [
                    'CHF' => 20,
                    'EUR' => 30,
                ];

                $product = Product::create([
                    'name' => 'Test 2',
                    'slug' => 'test-2',
                    'stock' => 90,
                    'published' => true,
                ]);
                $product->price = [
                    'CHF' => 30,
                    'EUR' => 40,
                ];
            }

            return;
        }

        Artisan::call('posmall:seed-wings-of-win', [
            '--force' => true,
            '--without-index' => true,
        ]);
    }
}
