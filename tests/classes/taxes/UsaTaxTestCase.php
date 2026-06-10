<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use October\Rain\Database\Schema\Blueprint;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Classes\Utils\Money;
use KodZero\POSMall\Tests\PluginTestCase;

abstract class UsaTaxTestCase extends PluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessSafeDestructiveTaxTestDatabase();

        app()->singleton(Money::class, fn () => new class implements Money {
            public function format(?float $value, $product = null, ?Currency $currency = null): string
            {
                return (string)$value;
            }

            public function round($value, $decimals = 2): float
            {
                return round((float)$value / 100, $decimals ?? 2);
            }
        });

        foreach ([
            'kodzero_posmall_category_tax',
            'kodzero_posmall_category_product',
            'kodzero_posmall_product_tax',
            'kodzero_posmall_usa_tax_histories',
            'kodzero_posmall_usa_tax_import_staging',
            'kodzero_posmall_usa_tax_region_rows',
            'kodzero_posmall_tax_group_codes',
            'kodzero_posmall_products',
            'kodzero_posmall_categories',
            'kodzero_posmall_taxes',
            'kodzero_posmall_currencies',
            'system_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Cache::forget(Currency::CURRENCIES_CACHE_KEY);
        Cache::forget(Currency::DEFAULT_CURRENCY_CACHE_KEY);
        Cache::forget(Currency::JSON_PRICE_CACHE_KEY);
        Session::forget(Currency::CURRENCY_SESSION_KEY);
        Currency::$defaultCurrency = null;

        $this->createSettingsTable();
        $this->createCurrencyTable();
        $this->createTaxTables();
        $this->createCatalogTables();
    }

    protected function skipUnlessSafeDestructiveTaxTestDatabase(): void
    {
        $database = (string)config('database.connections.' . config('database.default') . '.database');
        $safeName = $database !== ''
            && preg_match('/(^|[_-])(test|testing|phpunit)([_-]|$)/i', $database) === 1;

        if (app()->runningUnitTests() && $safeName) {
            return;
        }

        $this->markTestSkipped(sprintf(
            'USA tax tests use destructive table setup and require a dedicated test database; current database is [%s].',
            $database ?: 'unknown'
        ));
    }

    protected function createProduct(string $name = 'USA Product'): int
    {
        return DB::table('kodzero_posmall_products')->insertGetId([
            'name' => $name,
            'slug' => str_slug($name),
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createCategory(string $name, ?int $parentId = null): int
    {
        $nextId = (int)DB::table('kodzero_posmall_categories')->max('id') + 1;

        return DB::table('kodzero_posmall_categories')->insertGetId([
            'name' => $name,
            'slug' => str_slug($name),
            'parent_id' => $parentId,
            'nest_left' => $nextId * 2 - 1,
            'nest_right' => $nextId * 2,
            'nest_depth' => $parentId === null ? 0 : 1,
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function createSettingsTable(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('item')->nullable()->index();
            $table->text('value')->nullable();
        });
    }

    protected function createCurrencyTable(): void
    {
        Schema::create('kodzero_posmall_currencies', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code');
            $table->string('symbol')->nullable();
            $table->decimal('rate', 12, 6)->default(1);
            $table->integer('decimals')->default(2);
            $table->string('format')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        DB::table('kodzero_posmall_currencies')->insert([
            'code' => 'USD',
            'symbol' => '$',
            'rate' => 1,
            'decimals' => 2,
            'format' => '${{ price }}',
            'is_default' => true,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tax(string $name, string $stateCode, string $groupCode, float $rate): Tax
    {
        return Tax::create([
            'name' => $name,
            'percentage' => $rate,
            'rate_percent' => $rate,
            'state_code' => $stateCode,
            'tax_group_code' => $groupCode,
            'tax_group_name' => $groupCode,
            'is_enabled' => true,
            'is_active' => true,
        ]);
    }

    protected function createTaxTables(): void
    {
        Schema::create('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->decimal('percentage', 8, 4)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->string('state_code', 2)->nullable();
            $table->text('state_codes')->nullable();
            $table->decimal('rate_percent', 8, 4)->nullable();
            $table->string('tax_group_code', 80)->nullable();
            $table->string('tax_group_name')->nullable();
            $table->text('tax_group_description')->nullable();
            $table->string('tax_main_group', 30)->nullable();
            $table->string('tax_main_group_name')->nullable();
            $table->string('taxability_mode', 60)->nullable();
            $table->string('jurisdiction_type', 30)->nullable();
            $table->string('jurisdiction_name')->nullable();
            $table->string('jurisdiction_code', 80)->nullable();
            $table->decimal('state_rate_percent', 8, 4)->nullable();
            $table->decimal('local_rate_percent', 8, 4)->nullable();
            $table->text('zip_code_hints')->nullable();
            $table->text('zip_code_ranges')->nullable();
            $table->text('region_names')->nullable();
            $table->text('county_names')->nullable();
            $table->text('city_names')->nullable();
            $table->text('zip_codes')->nullable();
            $table->text('zip_ranges')->nullable();
            $table->text('boundary_source_url')->nullable();
            $table->text('description')->nullable();
            $table->text('info')->nullable();
            $table->integer('source_rows_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('usa_auto_update_enabled')->default(false);
            $table->text('source_url')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->string('source_name')->nullable();
            $table->string('parser_name')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kodzero_posmall_tax_group_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tax_id');
            $table->string('tax_group_code', 80);
            $table->string('tax_group_name')->nullable();
            $table->text('tax_group_description')->nullable();
            $table->timestamps();
            $table->unique(['tax_id', 'tax_group_code']);
        });

        Schema::create('kodzero_posmall_usa_tax_import_staging', function (Blueprint $table) {
            $table->increments('id');
            $table->string('batch_id', 64);
            $table->string('state_code', 2)->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->string('source_name')->nullable();
            $table->string('parser_name')->nullable();
            $table->string('raw_name')->nullable();
            $table->string('parsed_name')->nullable();
            $table->string('tax_group_code', 80)->nullable();
            $table->string('tax_group_name')->nullable();
            $table->text('tax_group_description')->nullable();
            $table->string('taxability_mode', 60)->nullable();
            $table->string('jurisdiction_type', 30)->nullable();
            $table->string('jurisdiction_name')->nullable();
            $table->string('jurisdiction_code', 80)->nullable();
            $table->decimal('state_rate_percent', 8, 4)->nullable();
            $table->decimal('local_rate_percent', 8, 4)->nullable();
            $table->text('zip_code_hints')->nullable();
            $table->text('zip_code_ranges')->nullable();
            $table->text('region_names')->nullable();
            $table->text('county_names')->nullable();
            $table->text('city_names')->nullable();
            $table->text('zip_codes')->nullable();
            $table->text('zip_ranges')->nullable();
            $table->text('boundary_source_url')->nullable();
            $table->decimal('rate_percent', 8, 4)->nullable();
            $table->text('description')->nullable();
            $table->text('info')->nullable();
            $table->integer('source_rows_count')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('kodzero_posmall_usa_tax_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tax_id')->nullable();
            $table->decimal('old_rate_percent', 8, 4)->nullable();
            $table->decimal('new_rate_percent', 8, 4)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('tax_group_code', 80)->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('kodzero_posmall_usa_tax_region_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->string('batch_id', 64)->nullable();
            $table->integer('group_id')->nullable();
            $table->integer('tax_id')->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('tax_main_group', 30)->nullable();
            $table->string('county_name')->nullable();
            $table->string('city_name')->nullable();
            $table->string('region_name')->nullable();
            $table->string('jurisdiction_name')->nullable();
            $table->string('jurisdiction_code', 120)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('zip_from', 20)->nullable();
            $table->string('zip_to', 20)->nullable();
            $table->string('zip4_from', 20)->nullable();
            $table->string('zip4_to', 20)->nullable();
            $table->decimal('state_rate_percent', 8, 4)->nullable();
            $table->decimal('county_rate_percent', 8, 4)->nullable();
            $table->decimal('city_rate_percent', 8, 4)->nullable();
            $table->decimal('district_rate_percent', 8, 4)->nullable();
            $table->decimal('local_rate_percent', 8, 4)->nullable();
            $table->decimal('total_rate_percent', 8, 4)->nullable();
            $table->string('tax_group_code', 80)->nullable();
            $table->string('taxability_mode', 60)->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->text('raw_payload')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });
    }

    protected function createCatalogTables(): void
    {
        Schema::create('kodzero_posmall_products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->boolean('sell_only_to_tax_states')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kodzero_posmall_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->integer('parent_id')->nullable();
            $table->integer('nest_left')->nullable();
            $table->integer('nest_right')->nullable();
            $table->integer('nest_depth')->nullable();
            $table->boolean('sell_only_to_tax_states')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kodzero_posmall_product_tax', function (Blueprint $table) {
            $table->integer('product_id');
            $table->integer('tax_id');
        });

        Schema::create('kodzero_posmall_category_tax', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('tax_id');
        });

        Schema::create('kodzero_posmall_category_product', function (Blueprint $table) {
            $table->integer('category_id');
            $table->integer('product_id');
            $table->integer('sort_order')->nullable();
        });
    }
}
