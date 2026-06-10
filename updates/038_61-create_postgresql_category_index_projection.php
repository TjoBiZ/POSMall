<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const CATEGORY_TABLE = 'kodzero_posmall_index_categories';
    private const PRICE_TABLE = 'kodzero_posmall_index_category_prices';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->createTables();
        $this->createIndexes();
        $this->backfill();
        $this->analyze();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::dropIfExists(self::PRICE_TABLE);
        Schema::dropIfExists(self::CATEGORY_TABLE);
    }

    private function createTables(): void
    {
        if (!Schema::hasTable(self::CATEGORY_TABLE)) {
            Schema::create(self::CATEGORY_TABLE, function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('index_id');
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_id')->nullable();
                $table->string('index_name', 32);
                $table->string('name', 191);
                $table->string('brand', 191)->default('');
                $table->boolean('published')->default(false);
                $table->integer('stock')->default(0);
                $table->decimal('reviews_rating', 3, 2)->default(0);
                $table->integer('sales_count')->default(0);
                $table->boolean('on_sale')->default(false);
                $table->boolean('is_ghost')->default(false);
                $table->integer('sort_order')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable(self::PRICE_TABLE)) {
            Schema::create(self::PRICE_TABLE, function ($table): void {
                $table->bigIncrements('id');
                $table->unsignedInteger('index_id');
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_id')->nullable();
                $table->string('index_name', 32);
                $table->string('currency_code', 16);
                $table->bigInteger('price');
            });
        }
    }

    private function createIndexes(): void
    {
        $indexes = [
            'idx_kodzero_posmall_index_categories_unique' => sprintf(
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_id, category_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_unique'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_sales' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, sales_count DESC, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_sales'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_rating' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, reviews_rating DESC, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_rating'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_created' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, created_at DESC, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_created'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_name' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, name, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_name'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_manual' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, sort_order, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_manual'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_brand' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, brand)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_brand'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_categories_product' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, product_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_product'),
                $this->quoteIdentifier(self::CATEGORY_TABLE)
            ),
            'idx_kodzero_posmall_index_category_prices_lookup' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, currency_code, price, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_prices_lookup'),
                $this->quoteIdentifier(self::PRICE_TABLE)
            ),
            'idx_kodzero_posmall_index_category_prices_product' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, product_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_prices_product'),
                $this->quoteIdentifier(self::PRICE_TABLE)
            ),
            'idx_kodzero_posmall_image_sets_product_id' => sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (product_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_image_sets_product_id'),
                $this->quoteIdentifier('kodzero_posmall_image_sets')
            ),
        ];

        foreach ($indexes as $sql) {
            DB::statement($sql);
        }
    }

    private function backfill(): void
    {
        if (!Schema::hasTable('kodzero_posmall_index')) {
            return;
        }

        DB::statement(sprintf('TRUNCATE TABLE %s, %s', $this->quoteIdentifier(self::PRICE_TABLE), $this->quoteIdentifier(self::CATEGORY_TABLE)));

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (
    index_id, category_id, product_id, variant_id, index_name, name, brand, published, stock,
    reviews_rating, sales_count, on_sale, is_ghost, sort_order, created_at
)
select
    i.id,
    category.category_id::integer,
    i.product_id,
    i.variant_id,
    i.index,
    i.name,
    i.brand,
    i.published,
    i.stock,
    i.reviews_rating,
    i.sales_count,
    i.on_sale,
    i.is_ghost,
    nullif(i.sort_orders ->> category.category_id, '')::integer,
    i.created_at
from %2$s i
cross join lateral jsonb_array_elements_text(i.category_id) as category(category_id)
where i.published = true
SQL,
            $this->quoteIdentifier(self::CATEGORY_TABLE),
            $this->quoteIdentifier('kodzero_posmall_index')
        ));

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (
    index_id, category_id, product_id, variant_id, index_name, currency_code, price
)
select
    i.id,
    category.category_id::integer,
    i.product_id,
    i.variant_id,
    i.index,
    price.currency_code,
    price.price_value::bigint
from %2$s i
cross join lateral jsonb_array_elements_text(i.category_id) as category(category_id)
cross join lateral jsonb_each_text(
    case when i.prices = '{}'::jsonb then i.parent_prices else i.prices end
) as price(currency_code, price_value)
where i.published = true
  and price.price_value ~ '^-?[0-9]+$'
SQL,
            $this->quoteIdentifier(self::PRICE_TABLE),
            $this->quoteIdentifier('kodzero_posmall_index')
        ));
    }

    private function analyze(): void
    {
        foreach ([self::CATEGORY_TABLE, self::PRICE_TABLE, 'kodzero_posmall_image_sets'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            DB::statement('ANALYZE ' . $this->quoteIdentifier($table));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
