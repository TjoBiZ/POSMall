<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Benchmark;

use DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\Bestseller;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\ProductEntry;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\LoadBenchmarkRun;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;
use KodZero\POSMall\Models\Service;

class LoadBenchmark
{
    private const SKU_PREFIX = 'POSMALL-LOAD-';
    private const SERVICE_CODE_PREFIX = 'posmall-load-service-';
    private const CHUNK_SIZE = 1000;
    private const CATEGORY_PROJECTION_TABLE = 'kodzero_posmall_index_categories';
    private const CATEGORY_PRICE_PROJECTION_TABLE = 'kodzero_posmall_index_category_prices';
    private const CATEGORY_STATS_PROJECTION_TABLE = 'kodzero_posmall_index_category_stats';
    private const CATEGORY_BRANDS_PROJECTION_TABLE = 'kodzero_posmall_index_category_brands';

    private const COLORS = ['red', 'blue', 'green', 'black', 'white', 'gold', 'silver', 'purple'];
    private const MATERIALS = ['carbon', 'silk', 'cotton', 'wool', 'linen', 'paper'];

    private Currency $currency;
    private Brand $brand;
    private Category $physicalCategory;
    private Category $virtualCategory;
    private Category $serviceCategory;
    private Property $colorProperty;
    private Property $materialProperty;

    public function run(int $targetRecords, int $iterations = 10, bool $seed = true, bool $withImages = false): LoadBenchmarkRun
    {
        $this->guard();

        $run = LoadBenchmarkRun::create([
            'target_records' => $targetRecords,
            'iterations' => $iterations,
            'status' => LoadBenchmarkRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $started = microtime(true);

        try {
            $this->bootContext();

            $seedSeconds = null;
            if ($seed) {
                $seedStart = microtime(true);
                $this->replaceLoadData($targetRecords, $withImages);
                $seedSeconds = (int)round(microtime(true) - $seedStart);
            } else {
                $this->syncCatalogShape();
            }

            $benchmarkStart = microtime(true);
            $metrics = $this->benchmark($iterations);
            $benchmarkSeconds = (int)round(microtime(true) - $benchmarkStart);

            $finished = now();
            $run->fill([
                'actual_products' => DB::table('kodzero_posmall_products')
                    ->where('user_defined_id', 'like', self::SKU_PREFIX . '%')
                    ->count(),
                'actual_services' => DB::table('kodzero_posmall_services')
                    ->where('code', 'like', self::SERVICE_CODE_PREFIX . '%')
                    ->count(),
                'actual_index_rows' => $this->loadIndexRowsCount(),
                'status' => LoadBenchmarkRun::STATUS_PASSED,
                'finished_at' => $finished,
                'duration_seconds' => (int)round(microtime(true) - $started),
                'seed_seconds' => $seedSeconds,
                'benchmark_seconds' => $benchmarkSeconds,
                'category_avg_ms' => $metrics['category']['avg_ms'],
                'filtered_avg_ms' => $metrics['filtered']['avg_ms'],
                'search_avg_ms' => $metrics['search']['avg_ms'],
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'metrics' => $metrics,
                'explain_plans' => $this->explainPlans(),
            ])->save();
        } catch (\Throwable $exception) {
            $run->fill([
                'status' => LoadBenchmarkRun::STATUS_ERROR,
                'finished_at' => now(),
                'duration_seconds' => (int)round(microtime(true) - $started),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'error_output' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return $run->fresh();
    }

    private function guard(): void
    {
        if (!app()->environment(['local', 'dev', 'development', 'testing'])) {
            throw new \RuntimeException('POSMall load benchmarks are only available in local/dev/testing environments.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('POSMall load benchmarks require PostgreSQL.');
        }
    }

    private function bootContext(): void
    {
        $this->currency = Currency::where('code', 'USD')->first() ?? Currency::where('is_enabled', true)->firstOrFail();
    }

    private function replaceLoadData(int $targetRecords, bool $withImages): void
    {
        $this->deleteLoadData();
        $this->syncCatalogShape();
        if ($withImages) {
            app(CatalogImageOptimizer::class)->ensureOptimized([CatalogImageOptimizer::PROFILE_CATALOG]);
        }
        $this->insertProducts($targetRecords, $withImages);
        $this->refreshProjectionSummaries();
        $this->insertServices(max(25, min(500, (int)ceil($targetRecords / 1000) * 10)));
        $this->analyzeHotTables();
    }

    public function purge(): void
    {
        $this->guard();
        $this->deleteLoadData();
        $this->analyzeHotTables();
    }

    private function deleteLoadData(): void
    {
        DB::table('system_files')
            ->where('attachment_type', 'posmall.imageset')
            ->whereIn('attachment_id', $this->loadImageSetIdSubquery())
            ->delete();
        DB::table('kodzero_posmall_image_sets')->whereIn('product_id', $this->loadProductIdSubquery())->delete();
        $this->deleteProjectionLoadData();
        DB::table('kodzero_posmall_index')->whereIn('product_id', $this->loadProductIdSubquery())->delete();
        DB::table('kodzero_posmall_product_prices')->whereIn('product_id', $this->loadProductIdSubquery())->delete();
        DB::table('kodzero_posmall_product_prices')->whereIn('product_id', $this->loadServiceCarrierProductIdSubquery())->delete();
        DB::table('kodzero_posmall_property_values')->whereIn('product_id', $this->loadProductIdSubquery())->delete();
        DB::table('kodzero_posmall_category_product')->whereIn('product_id', $this->loadProductIdSubquery())->delete();
        DB::table('kodzero_posmall_category_product')->whereIn('product_id', $this->loadServiceCarrierProductIdSubquery())->delete();
        DB::table('kodzero_posmall_product_service')->whereIn('product_id', $this->loadProductIdSubquery())->delete();

        DB::table('kodzero_posmall_prices')
            ->where('priceable_type', 'posmall.service_option')
            ->whereIn('priceable_id', $this->loadServiceOptionIdSubquery())
            ->delete();

        DB::table('kodzero_posmall_service_options')->whereIn('service_id', $this->loadServiceIdSubquery())->delete();
        DB::table('kodzero_posmall_product_service')->whereIn('service_id', $this->loadServiceIdSubquery())->delete();
        DB::table('kodzero_posmall_services')->where('code', 'like', self::SERVICE_CODE_PREFIX . '%')->delete();
        DB::table('kodzero_posmall_products')->where('user_defined_id', 'like', $this->serviceCarrierSkuLike())->delete();
        DB::table('kodzero_posmall_products')->where('user_defined_id', 'like', self::SKU_PREFIX . '%')->delete();

        $this->refreshProjectionSummaries();
    }

    private function deleteProjectionLoadData(): void
    {
        if (!$this->projectionTablesExist()) {
            return;
        }

        DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->whereIn('product_id', $this->loadProductIdSubquery())
            ->delete();
        DB::table(self::CATEGORY_PROJECTION_TABLE)
            ->whereIn('product_id', $this->loadProductIdSubquery())
            ->delete();
    }

    private function syncCatalogShape(): void
    {
        $this->brand = Brand::firstOrCreate(
            ['slug' => 'posmall-load-benchmark'],
            [
                'name' => 'POSMall Load Benchmark',
                'description' => 'Synthetic local benchmark brand.',
                'sort_order' => 9000,
            ]
        );

        $this->physicalCategory = $this->category('posmall-load-physical', 'Load Benchmark Physical');
        $this->virtualCategory = $this->category('posmall-load-virtual', 'Load Benchmark Virtual');
        $this->serviceCategory = $this->category('posmall-load-services', 'Load Benchmark Services');

        $group = PropertyGroup::firstOrCreate(
            ['slug' => 'posmall-load-properties'],
            [
                'name' => 'POSMall Load Properties',
                'display_name' => 'Load properties',
                'description' => 'Synthetic benchmark filter properties.',
            ]
        );

        $this->colorProperty = $this->property('posmall-load-color', 'Load color', 'dropdown');
        $this->materialProperty = $this->property('posmall-load-material', 'Load material', 'dropdown');

        foreach ([$this->physicalCategory, $this->virtualCategory, $this->serviceCategory] as $category) {
            DB::table('kodzero_posmall_category_property_group')->updateOrInsert([
                'category_id' => $category->id,
                'property_group_id' => $group->id,
            ], [
                'relation_sort_order' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }

        foreach ([$this->colorProperty, $this->materialProperty] as $property) {
            DB::table('kodzero_posmall_property_property_group')->updateOrInsert([
                'property_id' => $property->id,
                'property_group_id' => $group->id,
            ], [
                'use_for_variants' => false,
                'filter_type' => 'set',
                'sort_order' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]);
        }
    }

    private function category(string $slug, string $name): Category
    {
        return Category::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'description_short' => $name,
                'description' => $name . ' generated for local load benchmarks.',
                'inherit_property_groups' => true,
                'inherit_review_categories' => true,
                'sort_order' => 9000,
            ]
        );
    }

    private function property(string $slug, string $name, string $type): Property
    {
        return Property::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => $type,
            ]
        );
    }

    private function insertProducts(int $targetRecords, bool $withImages): void
    {
        $now = now();
        $imagePool = $withImages ? $this->imagePool() : [];
        $productRows = [];
        $priceRows = [];
        $categoryRows = [];
        $propertyRows = [];
        $indexRows = [];
        $projectionRows = [];
        $projectionPriceRows = [];
        $imageSetRows = [];
        $fileRows = [];

        for ($i = 1; $i <= $targetRecords; $i++) {
            $productId = $this->nextProductId($i);
            $isVirtual = $i % 5 === 0;
            $category = $isVirtual ? $this->virtualCategory : $this->physicalCategory;
            $color = self::COLORS[$i % count(self::COLORS)];
            $material = self::MATERIALS[$i % count(self::MATERIALS)];
            $price = 1500 + (($i % 900) * 25);

            $productRows[] = [
                'id' => $productId,
                'brand_id' => $this->brand->id,
                'user_defined_id' => self::SKU_PREFIX . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                'name' => sprintf('Load %s Product %06d %s %s', $isVirtual ? 'Virtual' : 'Physical', $i, $color, $material),
                'slug' => 'posmall-load-product-' . str_pad((string)$i, 6, '0', STR_PAD_LEFT),
                'description_short' => 'Synthetic POSMall PostgreSQL load benchmark product.',
                'description' => 'Synthetic POSMall PostgreSQL load benchmark product generated locally.',
                'inventory_management_method' => 'single',
                'stock' => $isVirtual ? 999999 : (($i % 200) + 1),
                'allow_out_of_stock_purchases' => false,
                'stackable' => true,
                'shippable' => !$isVirtual,
                'price_includes_tax' => true,
                'published' => true,
                'sales_count' => $i % 1000,
                'reviews_rating' => (($i % 50) + 1) / 10,
                'is_virtual' => $isVirtual,
                'file_session_required' => $isVirtual,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $priceRows[] = [
                'product_id' => $productId,
                'variant_id' => null,
                'currency_id' => $this->currency->id,
                'price' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $categoryRows[] = [
                'product_id' => $productId,
                'category_id' => $category->id,
                'sort_order' => $i,
            ];

            $propertyRows[] = $this->propertyValueRow($productId, $this->colorProperty->id, $color, $now);
            $propertyRows[] = $this->propertyValueRow($productId, $this->materialProperty->id, $material, $now);
            if ($withImages) {
                $imageSetId = $this->nextImageSetId($i);
                $sourceImage = $imagePool[(($i * 73) + 19) % count($imagePool)];
                $imageSetRows[] = [
                    'id' => $imageSetId,
                    'name' => sprintf('Load benchmark image set %06d', $i),
                    'product_id' => $productId,
                    'is_main_set' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $fileRows[] = $this->systemFileRow($imageSetId, $sourceImage, $i, $now);
            }

            $indexId = $this->nextIndexId($i);

            $indexRows[] = [
                'id' => $indexId,
                'product_id' => $productId,
                'variant_id' => null,
                'index' => ProductEntry::INDEX,
                'name' => sprintf('Load Product %06d', $i),
                'brand' => $this->brand->slug,
                'published' => true,
                'stock' => $isVirtual ? 999999 : (($i % 200) + 1),
                'reviews_rating' => (($i % 50) + 1) / 10,
                'sales_count' => $i % 1000,
                'on_sale' => $i % 17 === 0,
                'is_ghost' => false,
                'category_id' => json_encode([$category->id]),
                'property_values' => json_encode([
                    (string)$this->colorProperty->id => [$color],
                    (string)$this->materialProperty->id => [$material],
                ]),
                'sort_orders' => json_encode([(string)$category->id => $i]),
                'prices' => json_encode([$this->currency->code => $price]),
                'parent_prices' => json_encode([]),
                'customer_group_prices' => json_encode([]),
                'created_at' => $now,
            ];

            if ($this->projectionTablesExist()) {
                $projectionRows[] = [
                    'index_id' => $indexId,
                    'category_id' => $category->id,
                    'product_id' => $productId,
                    'variant_id' => null,
                    'index_name' => ProductEntry::INDEX,
                    'name' => sprintf('Load Product %06d', $i),
                    'brand' => $this->brand->slug,
                    'published' => true,
                    'stock' => $isVirtual ? 999999 : (($i % 200) + 1),
                    'reviews_rating' => (($i % 50) + 1) / 10,
                    'sales_count' => $i % 1000,
                    'on_sale' => $i % 17 === 0,
                    'is_ghost' => false,
                    'sort_order' => $i,
                    'created_at' => $now,
                ];
                $projectionPriceRows[] = [
                    'index_id' => $indexId,
                    'category_id' => $category->id,
                    'product_id' => $productId,
                    'variant_id' => null,
                    'index_name' => ProductEntry::INDEX,
                    'currency_code' => $this->currency->code,
                    'price' => $price,
                ];
            }

            if (count($productRows) >= self::CHUNK_SIZE) {
                $this->flushProductRows(
                    $productRows,
                    $priceRows,
                    $categoryRows,
                    $propertyRows,
                    $indexRows,
                    $projectionRows,
                    $projectionPriceRows,
                    $imageSetRows,
                    $fileRows
                );
            }
        }

        $this->flushProductRows(
            $productRows,
            $priceRows,
            $categoryRows,
            $propertyRows,
            $indexRows,
            $projectionRows,
            $projectionPriceRows,
            $imageSetRows,
            $fileRows
        );
        $this->insertUniquePropertyValues();
    }

    private function imagePool(): array
    {
        $images = DB::table('system_files')
            ->select('disk_name', 'file_name', 'file_size', 'content_type', 'title', 'description')
            ->where('attachment_type', 'posmall.imageset')
            ->whereIn('content_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->where('file_size', '>', 2048)
            ->orderBy('id')
            ->limit(256)
            ->get()
            ->map(fn ($row) => (array)$row)
            ->all();

        if ($images === []) {
            throw new \RuntimeException('No existing POSMall raster images were found for the load benchmark image mode.');
        }

        return $images;
    }

    private function systemFileRow(int $imageSetId, array $sourceImage, int $offset, $now): array
    {
        $sourceStem = pathinfo((string)$sourceImage['file_name'], PATHINFO_FILENAME);
        $sourceExtension = strtolower(pathinfo((string)$sourceImage['file_name'], PATHINFO_EXTENSION)) ?: 'jpg';
        $seoStem = Str::slug($sourceStem) ?: 'posmall-product-image';

        return [
            'disk_name' => $sourceImage['disk_name'],
            'file_name' => sprintf('%s-load-%06d.%s', $seoStem, $offset, $sourceExtension),
            'file_size' => $sourceImage['file_size'],
            'content_type' => $sourceImage['content_type'],
            'title' => 'POSMall load benchmark image',
            'description' => 'Synthetic benchmark attachment that reuses an existing local file.',
            'field' => 'images',
            'attachment_id' => $imageSetId,
            'attachment_type' => 'posmall.imageset',
            'is_public' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function insertServices(int $count): void
    {
        $now = now();
        $serviceRows = [];
        $optionRows = [];
        $priceRows = [];
        $carrierProductRows = [];
        $carrierPriceRows = [];
        $carrierCategoryRows = [];

        for ($i = 1; $i <= $count; $i++) {
            $serviceId = $this->nextServiceId($i);
            $optionId = $this->nextServiceOptionId($i);
            $serviceCode = $this->serviceCode($i);
            $carrierProductId = $this->nextServiceCarrierProductId($i);

            $serviceRows[] = [
                'id' => $serviceId,
                'name' => sprintf('Load Benchmark Service %04d', $i),
                'code' => $serviceCode,
                'description' => 'Synthetic local benchmark service.',
                'created_at' => $now,
                'updated_at' => $now,
                'sell_only_to_tax_states' => false,
            ];

            $optionRows[] = [
                'id' => $optionId,
                'service_id' => $serviceId,
                'name' => 'Standard load option',
                'description' => 'Synthetic benchmark service option.',
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $priceRows[] = [
                'priceable_id' => $optionId,
                'priceable_type' => 'posmall.service_option',
                'currency_id' => $this->currency->id,
                'price' => 500 + ($i % 100) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $carrierProductRows[] = [
                'id' => $carrierProductId,
                'brand_id' => $this->brand->id,
                'user_defined_id' => $this->serviceCarrierSku($serviceCode),
                'name' => sprintf('Load Benchmark Service %04d Carrier', $i),
                'slug' => 'posmall-load-service-carrier-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
                'description_short' => 'Hidden benchmark checkout carrier for standalone service options.',
                'description' => 'Hidden benchmark checkout carrier used to price standalone service options.',
                'inventory_management_method' => 'single',
                'quantity_default' => 1,
                'quantity_min' => 1,
                'quantity_max' => 1,
                'stock' => 999999,
                'allow_out_of_stock_purchases' => true,
                'stackable' => false,
                'shippable' => false,
                'price_includes_tax' => false,
                'published' => true,
                'sales_count' => 0,
                'reviews_rating' => 0,
                'is_virtual' => true,
                'file_session_required' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $carrierPriceRows[] = [
                'product_id' => $carrierProductId,
                'variant_id' => null,
                'currency_id' => $this->currency->id,
                'price' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $carrierCategoryRows[] = [
                'product_id' => $carrierProductId,
                'category_id' => $this->serviceCategory->id,
                'sort_order' => $i,
            ];
        }

        foreach (array_chunk($serviceRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_services')->insert($rows);
        }
        foreach (array_chunk($optionRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_service_options')->insert($rows);
        }
        foreach (array_chunk($priceRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_prices')->insert($rows);
        }
        foreach (array_chunk($carrierProductRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_products')->insert($rows);
        }
        foreach (array_chunk($carrierPriceRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_product_prices')->insert($rows);
        }
        foreach (array_chunk($carrierCategoryRows, self::CHUNK_SIZE) as $rows) {
            DB::table('kodzero_posmall_category_product')->insert($rows);
        }
    }

    private function propertyValueRow(int $productId, int $propertyId, string $value, $now): array
    {
        return [
            'product_id' => $productId,
            'variant_id' => null,
            'property_id' => $propertyId,
            'value' => $value,
            'index_value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function flushProductRows(
        array &$productRows,
        array &$priceRows,
        array &$categoryRows,
        array &$propertyRows,
        array &$indexRows,
        array &$projectionRows,
        array &$projectionPriceRows,
        array &$imageSetRows,
        array &$fileRows
    ): void {
        if ($productRows === []) {
            return;
        }

        DB::table('kodzero_posmall_products')->insert($productRows);
        DB::table('kodzero_posmall_product_prices')->insert($priceRows);
        DB::table('kodzero_posmall_category_product')->insert($categoryRows);
        DB::table('kodzero_posmall_property_values')->insert($propertyRows);
        DB::table('kodzero_posmall_index')->insert($indexRows);
        if ($projectionRows !== []) {
            DB::table(self::CATEGORY_PROJECTION_TABLE)->insert($projectionRows);
            DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)->insert($projectionPriceRows);
        }
        if ($imageSetRows !== []) {
            DB::table('kodzero_posmall_image_sets')->insert($imageSetRows);
            DB::table('system_files')->insert($fileRows);
        }

        $productRows = [];
        $priceRows = [];
        $categoryRows = [];
        $propertyRows = [];
        $indexRows = [];
        $projectionRows = [];
        $projectionPriceRows = [];
        $imageSetRows = [];
        $fileRows = [];
    }

    private function insertUniquePropertyValues(): void
    {
        foreach ([$this->physicalCategory, $this->virtualCategory] as $category) {
            foreach ([[$this->colorProperty, self::COLORS], [$this->materialProperty, self::MATERIALS]] as [$property, $values]) {
                foreach ($values as $value) {
                    $propertyValueId = DB::table('kodzero_posmall_property_values')
                        ->where('property_id', $property->id)
                        ->where('index_value', $value)
                        ->value('id');

                    if (!$propertyValueId) {
                        continue;
                    }

                    DB::table('kodzero_posmall_unique_property_values')->updateOrInsert([
                        'property_id' => $property->id,
                        'category_id' => $category->id,
                        'index_value' => $value,
                    ], [
                        'property_value_id' => $propertyValueId,
                        'value' => $value,
                    ]);
                }
            }
        }
    }

    private function benchmark(int $iterations): array
    {
        $index = app(Index::class);
        $order = new Bestseller();

        $categoryFilters = new Collection([
            'category_id' => new SetFilter('category_id', [$this->physicalCategory->id]),
        ]);
        $filteredFilters = new Collection([
            'category_id' => new SetFilter('category_id', [$this->physicalCategory->id]),
            'color' => new SetFilter($this->colorProperty, ['red']),
            'material' => new SetFilter($this->materialProperty, ['carbon']),
        ]);

        return [
            'category' => $this->measure($iterations, fn () => $index->fetch(ProductEntry::INDEX, clone $categoryFilters, $order, 9, 1)),
            'filtered' => $this->measure($iterations, fn () => $index->fetch(ProductEntry::INDEX, clone $filteredFilters, $order, 9, 1)),
            'search' => $this->measure($iterations, fn () => DB::table('kodzero_posmall_products')
                ->select('id')
                ->whereNull('deleted_at')
                ->where('published', true)
                ->where('name', 'ILIKE', '%red carbon%')
                ->orderBy('name')
                ->orderBy('id')
                ->limit(9)
                ->get()),
        ];
    }

    private function measure(int $iterations, callable $callback): array
    {
        $times = [];
        $lastCount = null;

        $callback();

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $result = $callback();
            $times[] = (microtime(true) - $start) * 1000;
            $lastCount = property_exists($result, 'totalCount')
                ? $result->totalCount
                : (method_exists($result, 'count') ? $result->count() : null);
        }

        sort($times);

        return [
            'iterations' => $iterations,
            'avg_ms' => round(array_sum($times) / max(1, count($times)), 3),
            'min_ms' => round($times[0] ?? 0, 3),
            'p50_ms' => round($times[(int)floor((count($times) - 1) / 2)] ?? 0, 3),
            'max_ms' => round($times[count($times) - 1] ?? 0, 3),
            'last_count' => $lastCount,
        ];
    }

    private function explainPlans(): array
    {
        $categoryJson = json_encode([$this->physicalCategory->id]);
        $propertyJson = json_encode([
            (string)$this->colorProperty->id => ['red'],
            (string)$this->materialProperty->id => ['carbon'],
        ]);

        $plans = [];

        if ($this->projectionTablesExist()) {
            $plans['category_projection'] = $this->explain(
                'select product_id from kodzero_posmall_index_categories where index_name = ? and published = true and category_id = ? order by sales_count desc, index_id asc limit 9',
                [ProductEntry::INDEX, $this->physicalCategory->id]
            );
        }

        return $plans + [
            'category' => $this->explain(
                'select product_id from kodzero_posmall_index where "index" = ? and published = true and category_id @> ?::jsonb order by sales_count desc, id asc limit 9',
                [ProductEntry::INDEX, $categoryJson]
            ),
            'filtered' => $this->explain(
                'select product_id from kodzero_posmall_index where "index" = ? and published = true and category_id @> ?::jsonb and property_values @> ?::jsonb order by sales_count desc, id asc limit 9',
                [ProductEntry::INDEX, $categoryJson, $propertyJson]
            ),
            'search' => $this->explain(
                'select id from kodzero_posmall_products where deleted_at is null and published = true and name ilike ? order by name, id limit 9',
                ['%red carbon%']
            ),
        ];
    }

    private function explain(string $sql, array $bindings): array
    {
        return array_map(
            fn ($row) => $row->{'QUERY PLAN'},
            DB::select('EXPLAIN (ANALYZE, BUFFERS, COSTS OFF, FORMAT TEXT) ' . $sql, $bindings)
        );
    }

    private function analyzeHotTables(): void
    {
        foreach ([
            'kodzero_posmall_products',
            'kodzero_posmall_product_prices',
            'kodzero_posmall_property_values',
            'kodzero_posmall_category_product',
            'kodzero_posmall_image_sets',
            'system_files',
            'kodzero_posmall_index',
            self::CATEGORY_PROJECTION_TABLE,
            self::CATEGORY_PRICE_PROJECTION_TABLE,
            self::CATEGORY_STATS_PROJECTION_TABLE,
            self::CATEGORY_BRANDS_PROJECTION_TABLE,
            'kodzero_posmall_services',
            'kodzero_posmall_service_options',
        ] as $table) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::statement('ANALYZE ' . $this->quoteIdentifier($table));
        }
    }

    private function loadIndexRowsCount(): int
    {
        return DB::table('kodzero_posmall_index as i')
            ->join('kodzero_posmall_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.index', ProductEntry::INDEX)
            ->where('p.user_defined_id', 'like', self::SKU_PREFIX . '%')
            ->count();
    }

    private function projectionTablesExist(): bool
    {
        return DB::getSchemaBuilder()->hasTable(self::CATEGORY_PROJECTION_TABLE)
            && DB::getSchemaBuilder()->hasTable(self::CATEGORY_PRICE_PROJECTION_TABLE);
    }

    private function refreshProjectionSummaries(): void
    {
        if (!DB::getSchemaBuilder()->hasTable(self::CATEGORY_PROJECTION_TABLE)
            || !DB::getSchemaBuilder()->hasTable(self::CATEGORY_STATS_PROJECTION_TABLE)
            || !DB::getSchemaBuilder()->hasTable(self::CATEGORY_BRANDS_PROJECTION_TABLE)
        ) {
            return;
        }

        DB::table(self::CATEGORY_STATS_PROJECTION_TABLE)->delete();
        DB::table(self::CATEGORY_BRANDS_PROJECTION_TABLE)->delete();

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, total_count, updated_at)
select index_name, category_id, count(*)::integer, now()
from %2$s
group by index_name, category_id
SQL,
            $this->quoteIdentifier(self::CATEGORY_STATS_PROJECTION_TABLE),
            $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
        ));

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, brand, updated_at)
select distinct index_name, category_id, brand, now()
from %2$s
where brand <> ''
SQL,
            $this->quoteIdentifier(self::CATEGORY_BRANDS_PROJECTION_TABLE),
            $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
        ));
    }

    private function loadProductIdSubquery(): \Closure
    {
        return function ($query): void {
            $query->select('id')
                ->from('kodzero_posmall_products')
                ->where('user_defined_id', 'like', self::SKU_PREFIX . '%');
        };
    }

    private function loadImageSetIdSubquery(): \Closure
    {
        return function ($query): void {
            $query->select('id')
                ->from('kodzero_posmall_image_sets')
                ->whereIn('product_id', $this->loadProductIdSubquery());
        };
    }

    private function loadServiceCarrierProductIdSubquery(): \Closure
    {
        return function ($query): void {
            $query->select('id')
                ->from('kodzero_posmall_products')
                ->where('user_defined_id', 'like', $this->serviceCarrierSkuLike());
        };
    }

    private function loadServiceIdSubquery(): \Closure
    {
        return function ($query): void {
            $query->select('id')
                ->from('kodzero_posmall_services')
                ->where('code', 'like', self::SERVICE_CODE_PREFIX . '%');
        };
    }

    private function loadServiceOptionIdSubquery(): \Closure
    {
        return function ($query): void {
            $query->select('id')
                ->from('kodzero_posmall_service_options')
                ->whereIn('service_id', $this->loadServiceIdSubquery());
        };
    }

    private function nextProductId(int $offset): int
    {
        return 100000000 + $offset;
    }

    private function nextServiceId(int $offset): int
    {
        return 100000000 + $offset;
    }

    private function nextServiceOptionId(int $offset): int
    {
        return 100000000 + $offset;
    }

    private function nextServiceCarrierProductId(int $offset): int
    {
        return 400000000 + $offset;
    }

    private function nextImageSetId(int $offset): int
    {
        return 200000000 + $offset;
    }

    private function nextIndexId(int $offset): int
    {
        return 300000000 + $offset;
    }

    private function serviceCode(int $offset): string
    {
        return self::SERVICE_CODE_PREFIX . str_pad((string)$offset, 4, '0', STR_PAD_LEFT);
    }

    private function serviceCarrierSku(string $serviceCode): string
    {
        return 'POSMALL-SERVICE-CARRIER-' . strtoupper(str_replace('-', '_', $serviceCode));
    }

    private function serviceCarrierSkuLike(): string
    {
        return 'POSMALL-SERVICE-CARRIER-POSMALL_LOAD_SERVICE_%';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
