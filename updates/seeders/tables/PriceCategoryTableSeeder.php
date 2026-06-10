<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\PriceCategory;

class PriceCategoryTableSeeder extends Seeder
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
        
        $category = PriceCategory::firstOrCreate(['code' => 'old_price'], [
            'code'      => 'old_price',
            'name'      => trans('kodzero.posmall::demo.price_categories.old_price_name'),
            'title'     => trans('kodzero.posmall::demo.price_categories.old_price_label'),
        ]);
        $category->translateContext('de');

        $category = PriceCategory::firstOrCreate(['code' => 'msrp'], [
            'code'      => 'msrp',
            'name'      => trans('kodzero.posmall::demo.price_categories.msrp_price_name'),
            'title'     => trans('kodzero.posmall::demo.price_categories.msrp_price_label'),
        ]);
        $category->translateContext('de');
    }
}
