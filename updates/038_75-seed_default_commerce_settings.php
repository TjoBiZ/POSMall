<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use Illuminate\Support\Facades\Cache;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Updates\Seeders\POSMallSeeder;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new POSMallSeeder())->run();

        Cache::forget(Currency::CURRENCIES_CACHE_KEY);
        Cache::forget(Currency::DEFAULT_CURRENCY_CACHE_KEY);
        Cache::forget(Currency::JSON_PRICE_CACHE_KEY);
        Currency::$defaultCurrency = null;
    }

    public function down(): void
    {
    }
};
