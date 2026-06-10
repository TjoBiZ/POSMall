<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use Illuminate\Support\Facades\Cache;
use KodZero\POSMall\Models\Currency;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Currency::query()->update(['is_default' => false]);

        Currency::query()->updateOrCreate(
            ['code' => 'USD'],
            [
                'code'       => 'USD',
                'format'     => '{{ currency.symbol }} {{ price|number_format(2, ".", ",") }}',
                'decimals'   => 2,
                'symbol'     => '$',
                'rate'       => 1.0,
                'is_default' => true,
                'is_enabled' => true,
            ]
        );

        Cache::forget(Currency::CURRENCIES_CACHE_KEY);
        Cache::forget(Currency::DEFAULT_CURRENCY_CACHE_KEY);
        Cache::forget(Currency::JSON_PRICE_CACHE_KEY);
        Currency::$defaultCurrency = null;
    }

    public function down(): void
    {
    }
};
