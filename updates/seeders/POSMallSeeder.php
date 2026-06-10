<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Updates\Seeders\Tables\CurrencyTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\NotificationTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\OrderStateTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\PaymentMethodTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\PriceCategoryTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\ShippingMethodTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\TaxTableSeeder;

class POSMallSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @return void
     */
    public function run()
    {
        $this->call([
            PriceCategoryTableSeeder::class,
            CurrencyTableSeeder::class,
            TaxTableSeeder::class,
            PaymentMethodTableSeeder::class,
            ShippingMethodTableSeeder::class,
            OrderStateTableSeeder::class,
            NotificationTableSeeder::class,
        ]);
    }
}
