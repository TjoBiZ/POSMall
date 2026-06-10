<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\CustomerGroup;

class CustomerGroupTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if (!$useDemo && config('app.env') != 'testing') {
            return;
        }
        
        CustomerGroup::firstOrCreate(['code' => 'silver'], [
            'name' => trans('kodzero.posmall::demo.customer_groups.silver.name'),
            'code' => 'silver',
        ]);

        CustomerGroup::firstOrCreate(['code' => 'gold'], [
            'name' => trans('kodzero.posmall::demo.customer_groups.gold.name'),
            'code' => 'gold',
        ]);
        
        CustomerGroup::firstOrCreate(['code' => 'diamond'], [
            'name' => trans('kodzero.posmall::demo.customer_groups.diamond.name'),
            'code' => 'diamond',
        ]);
    }
}
