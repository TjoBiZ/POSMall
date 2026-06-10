<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\Currency;

class CurrencyTableSeeder extends Seeder
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
        
        Currency::firstOrCreate(['code' => 'EUR'], [
            'code'       => 'EUR',
            'format'     => '{{ price|number_format(2, ",", ".") }}{{ currency.symbol }}',
            'decimals'   => 2,
            'symbol'     => '€',
            'rate'       => 0.93,
            'is_default' => false,
        ]);

        Currency::firstOrCreate(['code' => 'CHF'], [
            'code'       => 'CHF',
            'format'     => '{{ currency.code }} {{ price|number_format(2, ".", "\'") }}',
            'decimals'   => 2,
            'symbol'     => '₣',
            'rate'       => 0.94,
            'is_default' => false,
        ]);
        
        Currency::firstOrCreate(['code' => 'USD'], [
            'code'       => 'USD',
            'format'     => '{{ currency.symbol }} {{ price|number_format(2, ".", ",") }}',
            'decimals'   => 2,
            'symbol'     => '$',
            'rate'       => 1.0,
            'is_default' => true,
        ]);
    }
}
