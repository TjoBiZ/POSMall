<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Benchmark;

use DB;
use KodZero\POSMall\Classes\Benchmark\LoadBenchmark;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\ProductEntry;
use KodZero\POSMall\Classes\Index\PostgreSQL\PostgreSQL;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\LoadBenchmarkRun;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Tests\PluginTestCase;

class LoadBenchmarkTest extends PluginTestCase
{
    public function test_local_load_benchmark_records_metrics_and_can_reuse_existing_seed_data(): void
    {
        app()->forgetInstance(Index::class);
        app()->singleton(Index::class, fn () => new PostgreSQL());

        $benchmark = app(LoadBenchmark::class);
        $benchmark->purge();

        $seededRun = $benchmark->run(60, 2);
        $reuseRun = $benchmark->run(60, 2, false);

        $this->assertSame(LoadBenchmarkRun::STATUS_PASSED, $seededRun->status);
        $this->assertSame(60, $seededRun->actual_products);
        $this->assertSame(60, $seededRun->actual_index_rows);
        $this->assertGreaterThanOrEqual(25, $seededRun->actual_services);
        $this->assertIsArray($seededRun->metrics);
        $this->assertArrayHasKey('category', $seededRun->metrics);
        $this->assertArrayHasKey('filtered', $seededRun->metrics);
        $this->assertArrayHasKey('search', $seededRun->metrics);
        $this->assertIsArray($seededRun->explain_plans);
        $this->assertArrayHasKey('filtered', $seededRun->explain_plans);

        $this->assertSame(LoadBenchmarkRun::STATUS_PASSED, $reuseRun->status);
        $this->assertSame(60, $reuseRun->actual_products);
        $this->assertNull($reuseRun->seed_seconds);

        $benchmark->purge();
    }

    public function test_postgresql_category_projection_supports_price_sort_and_publish_sync(): void
    {
        app()->forgetInstance(Index::class);
        app()->singleton(Index::class, fn () => new PostgreSQL());

        $benchmark = app(LoadBenchmark::class);
        $benchmark->purge();
        $benchmark->run(60, 2);

        $category = Category::where('slug', 'posmall-load-physical')->firstOrFail();

        $categoryProjectionRows = DB::table('kodzero_posmall_index_categories')
            ->where('category_id', $category->id)
            ->count();

        $this->assertGreaterThan(0, $categoryProjectionRows);
        $this->assertGreaterThan(
            0,
            DB::table('kodzero_posmall_index_category_prices')
                ->where('category_id', $category->id)
                ->count()
        );

        $filters = collect([
            'category_id' => new SetFilter('category_id', [$category->id]),
        ]);
        $priceLow = SortOrder::fromKey('price_low')->setFilters(clone $filters);

        $result = app(Index::class)->fetch(ProductEntry::INDEX, $filters, $priceLow, 9, 1);

        $this->assertCount(9, $result->ids);
        $this->assertSame($categoryProjectionRows, $result->totalCount);

        $product = Product::where('user_defined_id', 'like', 'POSMALL-LOAD-%')
            ->whereIn('id', $result->ids)
            ->firstOrFail();
        $product->published = false;
        $product->save();

        app(Index::class)->update(ProductEntry::INDEX, $product->id, new ProductEntry($product->fresh()));

        $this->assertFalse(
            DB::table('kodzero_posmall_index_categories')
                ->where('category_id', $category->id)
                ->where('product_id', $product->id)
                ->exists()
        );

        $benchmark->purge();
    }
}
