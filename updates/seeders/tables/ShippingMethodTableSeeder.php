<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\ShippingMethod;

class ShippingMethodTableSeeder extends Seeder
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
        
        $method = ShippingMethod::firstOrCreate(['name' => trans('kodzero.posmall::demo.shipping_methods.standard')], [
            'name'          => trans('kodzero.posmall::demo.shipping_methods.standard'),
            'sort_order'    => 1,
            'is_default'    => true,
        ]);
        if (!$method->prices()->exists()) {
            $this->syncPrices($method, [
                'EUR' => 10,
                'CHF' => 12,
                'USD' => 15,
            ]);
        }
        
        $method = ShippingMethod::firstOrCreate(['name' => trans('kodzero.posmall::demo.shipping_methods.express')], [
            'name'                      => trans('kodzero.posmall::demo.shipping_methods.express'),
            'sort_order'                => 1,
            'is_default'                => false,
            'guaranteed_delivery_days'  => 3,
        ]);
        if (!$method->prices()->exists()) {
            $this->syncPrices($method, [
                'EUR' => 20,
                'CHF' => 24,
                'USD' => 30,
            ]);
        }
    }

    private function syncPrices(ShippingMethod $method, array $prices): void
    {
        foreach ($prices as $currencyCode => $price) {
            $currencyId = Currency::where('code', $currencyCode)->value('id');

            if (!$currencyId) {
                continue;
            }

            $method->prices()->updateOrCreate([
                'currency_id' => $currencyId,
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ], [
                'price' => $price,
            ]);
        }
    }
}
