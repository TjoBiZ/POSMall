<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\Tax;

class TaxTableSeeder extends Seeder
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
        
        $country = 'de';

        if ($country == 'de') {
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.standard'), 'percentage' => 19], [
                'name'          => trans('kodzero.posmall::demo.taxes.standard'),
                'percentage'    => 19,
                'is_default'    => true,
            ]);
    
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.reduced'), 'percentage' => 7], [
                'name'          => trans('kodzero.posmall::demo.taxes.reduced'),
                'percentage'    => 7,
            ]);
        } elseif ($country == 'at') {
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.standard'), 'percentage' => 20], [
                'name'          => trans('kodzero.posmall::demo.taxes.standard'),
                'percentage'    => 20,
                'is_default'    => true,
            ]);
    
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.reduced'), 'percentage' => 10], [
                'name'          => trans('kodzero.posmall::demo.taxes.reduced'),
                'percentage'    => 10,
            ]);
        } elseif ($country == 'ch') {
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.standard'), 'percentage' => 8.1], [
                'name'          => trans('kodzero.posmall::demo.taxes.standard'),
                'percentage'    => 8.1,
                'is_default'    => true,
            ]);
    
            Tax::firstOrCreate(['name' => trans('kodzero.posmall::demo.taxes.reduced'), 'percentage' => 2.6], [
                'name'          => trans('kodzero.posmall::demo.taxes.reduced'),
                'percentage'    => 2.6,
            ]);
        }
    }
}
