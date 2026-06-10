<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\PaymentMethod;

class PaymentMethodTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if ($useDemo) {
            return;
        }
        
        PaymentMethod::firstOrCreate(['payment_provider' => 'offline'], [
            'name'              => trans('kodzero.posmall::demo.payment_methods.invoice'),
            'payment_provider'  => 'offline',
            'sort_order'        => 1,
            'is_default'        => true,
        ]);
        
        PaymentMethod::firstOrCreate(['payment_provider' => 'paypal-rest'], [
            'name'              => 'PayPal',
            'payment_provider'  => 'paypal-rest',
            'sort_order'        => 2,
        ]);

        PaymentMethod::firstOrCreate(['payment_provider' => 'stripe'], [
            'name'              => 'Stripe',
            'payment_provider'  => 'stripe',
            'sort_order'        => 3,
        ]);
    }
}
