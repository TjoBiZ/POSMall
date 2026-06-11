<?php

namespace KodZero\POSMall\Classes\Index\PostgreSQL;

use Cache;
use DB;
use Event;
use Illuminate\Support\Collection;
use October\Rain\Database\Schema\Blueprint;
use KodZero\POSMall\Classes\CategoryFilter\Filter;
use KodZero\POSMall\Classes\CategoryFilter\RangeFilter;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\Random;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Index\Entry;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\IndexResult;
use KodZero\POSMall\Models\Currency;
use Schema;

class PostgreSQL implements Index
{
    public const CACHE_KEY = 'kodzero_posmall.postgresql.index.exists';

    private const CATEGORY_PROJECTION_TABLE = 'kodzero_posmall_index_categories';
    private const CATEGORY_PRICE_PROJECTION_TABLE = 'kodzero_posmall_index_category_prices';
    private const CATEGORY_STATS_PROJECTION_TABLE = 'kodzero_posmall_index_category_stats';
    private const CATEGORY_BRANDS_PROJECTION_TABLE = 'kodzero_posmall_index_category_brands';

    private static ?bool $categoryProjectionReady = null;
    private static ?bool $categoryWriteProjectionReady = null;
    private static ?bool $categorySummaryProjectionReady = null;
    private static bool $categoryProjectionTablesEnsured = false;

    private const CONTAINMENT_INDEXES = [
        'idx_kodzero_posmall_index_category_id_gin_published' => 'category_id',
        'idx_kodzero_posmall_index_property_values_gin_published' => 'property_values',
    ];

    private const GENERAL_LISTING_INDEXES = [
        'idx_kodzero_posmall_index_catalog_bestseller' => [
            'columns' => ['index', 'sales_count DESC', 'product_id ASC'],
            'include' => ['variant_id', 'is_ghost'],
            'where' => 'published = true',
        ],
    ];

    private const JSON_SORT_FIELDS = [
        'prices',
        'parent_prices',
        'sort_orders',
        'property_values',
        'customer_group_prices',
    ];

    public function __construct()
    {
        $this->create('');
    }

    public function insert(string $index, Entry $entry)
    {
        $this->persist($index, $entry);
    }

    public function update(string $index, $id, Entry $entry)
    {
        $this->persist($index, $entry);
    }

    public function delete(string $index, $id)
    {
        $column = $index === 'products' ? 'product_id' : 'variant_id';

        if (starts_with($id, 'product-')) {
            $index = 'variants';
            $column = 'product_id';
            $id = str_replace('product-', '', $id);
        }

        $this->deleteCategoryProjectionByColumn($index, $column, $id);

        $this->db()
            ->where('index', $index)
            ->where($column, $id)
            ->delete();
    }

    public function create(string $index)
    {
        $tableName = $this->db()->table;

        if (Cache::has(self::CACHE_KEY)) {
            if (Schema::hasTable($tableName)) {
                $this->createBaseIndexes($tableName);
                $this->createContainmentIndexes($tableName);
                $this->createGeneralListingIndexes($tableName);
                $this->ensureCategoryProjectionTables();
                return;
            }

            Cache::forget(self::CACHE_KEY);
        }

        if (Schema::hasTable($tableName)) {
            $this->createBaseIndexes($tableName);
            $this->createContainmentIndexes($tableName);
            $this->createGeneralListingIndexes($tableName);
            $this->ensureCategoryProjectionTables();
            Cache::forever(self::CACHE_KEY, true);
            return;
        }

        Schema::create($tableName, function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('variant_id')->nullable();
            $table->string('index');
            $table->string('name', 191);
            $table->string('brand');
            $table->boolean('published');
            $table->integer('stock');
            $table->decimal('reviews_rating', 3, 2);
            $table->integer('sales_count')->default(0);
            $table->boolean('on_sale')->default(false);
            $table->boolean('is_ghost')->default(false);
            $table->jsonb('category_id');
            $table->jsonb('property_values');
            $table->jsonb('sort_orders');
            $table->jsonb('prices');
            $table->jsonb('parent_prices');
            $table->jsonb('customer_group_prices');
            $table->timestamp('created_at');

            $table->index(
                ['product_id', 'variant_id', 'index', 'is_ghost'],
                'idx_kodzero_posmall_product_variant_index_is_ghost'
            );

            $table->index(['index', 'published'], 'idx_kodzero_posmall_published_index');
        });

        Event::fire('posmall.index.postgresql.extendTable', [$tableName]);

        $this->createContainmentIndexes($tableName);
        $this->createGeneralListingIndexes($tableName);
        $this->ensureCategoryProjectionTables();

        Cache::forever(self::CACHE_KEY, true);
    }

    public function drop(string $index)
    {
        Cache::forget(self::CACHE_KEY);
        self::$categoryProjectionReady = null;
        self::$categoryWriteProjectionReady = null;
        self::$categorySummaryProjectionReady = null;
        self::$categoryProjectionTablesEnsured = false;

        Schema::dropIfExists(self::CATEGORY_BRANDS_PROJECTION_TABLE);
        Schema::dropIfExists(self::CATEGORY_STATS_PROJECTION_TABLE);
        Schema::dropIfExists(self::CATEGORY_PRICE_PROJECTION_TABLE);
        Schema::dropIfExists(self::CATEGORY_PROJECTION_TABLE);
        Schema::dropIfExists($this->db()->table);
    }

    public function fetch(
        string $index,
        Collection $filters,
        SortOrder $order,
        int $perPage,
        int $forPage
    ): IndexResult {
        $projected = $this->fetchFromCategoryProjection($index, clone $filters, $order, $perPage, $forPage);

        if ($projected instanceof IndexResult) {
            return $projected;
        }

        $skip = $perPage * ($forPage - 1);
        $countFilters = clone $filters;
        $query = $this->searchQuery($index, $filters, $order);
        $countQuery = clone $query;

        $slice = array_map(function ($item) {
            return $item->is_ghost ? 'product-' . $item->other_id : $item->id;
        }, $query->offset($skip)->limit($perPage)->get()->toArray());

        return new IndexResult($slice, $this->fallbackIndexCount($index, $countFilters, $countQuery));
    }

    protected function db()
    {
        return new IndexEntry();
    }

    protected function createBaseIndexes(string $tableName): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (%s, %s, %s, %s)',
            $this->quoteIdentifier('idx_kodzero_posmall_product_variant_index_is_ghost'),
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier('product_id'),
            $this->quoteIdentifier('variant_id'),
            $this->quoteIdentifier('index'),
            $this->quoteIdentifier('is_ghost')
        ));

        DB::statement(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s (%s, %s)',
            $this->quoteIdentifier('idx_kodzero_posmall_published_index'),
            $this->quoteIdentifier($tableName),
            $this->quoteIdentifier('index'),
            $this->quoteIdentifier('published')
        ));
    }

    protected function createContainmentIndexes(string $tableName): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::CONTAINMENT_INDEXES as $indexName => $column) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s USING gin (%s jsonb_path_ops) WHERE %s = true',
                $this->quoteIdentifier($indexName),
                $this->quoteIdentifier($tableName),
                $this->quoteIdentifier($column),
                $this->quoteIdentifier('published')
            ));
        }
    }

    protected function createGeneralListingIndexes(string $tableName): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::GENERAL_LISTING_INDEXES as $indexName => $definition) {
            $include = empty($definition['include'])
                ? ''
                : ' INCLUDE (' . implode(', ', array_map([$this, 'quoteIdentifier'], $definition['include'])) . ')';

            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (%s)%s WHERE %s',
                $this->quoteIdentifier($indexName),
                $this->quoteIdentifier($tableName),
                implode(', ', array_map([$this, 'quoteIndexColumn'], $definition['columns'])),
                $include,
                $definition['where']
            ));
        }
    }

    protected function ensureCategoryProjectionTables(): void
    {
        if (self::$categoryProjectionTablesEnsured) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            self::$categoryProjectionTablesEnsured = true;
            return;
        }

        if (!Schema::hasTable(self::CATEGORY_PROJECTION_TABLE)) {
            Schema::create(self::CATEGORY_PROJECTION_TABLE, function (Blueprint $table) {
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

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (index_id, category_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_unique'),
                $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
            ));
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (index_name, category_id, sales_count DESC, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_sales'),
                $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
            ));
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (index_name, category_id, brand)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_categories_brand'),
                $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
            ));
        }

        if (!Schema::hasTable(self::CATEGORY_PRICE_PROJECTION_TABLE)) {
            Schema::create(self::CATEGORY_PRICE_PROJECTION_TABLE, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('index_id');
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('variant_id')->nullable();
                $table->string('index_name', 32);
                $table->string('currency_code', 16);
                $table->bigInteger('price');
            });

            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (index_name, category_id, currency_code, price, index_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_prices_lookup'),
                $this->quoteIdentifier(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ));
        }

        if (!Schema::hasTable(self::CATEGORY_STATS_PROJECTION_TABLE)) {
            Schema::create(self::CATEGORY_STATS_PROJECTION_TABLE, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('index_name', 32);
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('total_count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (index_name, category_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_stats_unique'),
                $this->quoteIdentifier(self::CATEGORY_STATS_PROJECTION_TABLE)
            ));
        }

        if (!Schema::hasTable(self::CATEGORY_BRANDS_PROJECTION_TABLE)) {
            Schema::create(self::CATEGORY_BRANDS_PROJECTION_TABLE, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('index_name', 32);
                $table->unsignedInteger('category_id');
                $table->string('brand', 191);
                $table->timestamp('updated_at')->nullable();
            });

            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (index_name, category_id, brand)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_brands_unique'),
                $this->quoteIdentifier(self::CATEGORY_BRANDS_PROJECTION_TABLE)
            ));
        }

        self::$categoryProjectionTablesEnsured = true;
    }

    protected function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    protected function quoteIndexColumn(string $column): string
    {
        if (str_ends_with($column, ' DESC')) {
            return $this->quoteIdentifier(substr($column, 0, -5)) . ' DESC';
        }

        if (str_ends_with($column, ' ASC')) {
            return $this->quoteIdentifier(substr($column, 0, -4)) . ' ASC';
        }

        return $this->quoteIdentifier($column);
    }

    protected function fetchFromCategoryProjection(
        string $index,
        Collection $filters,
        SortOrder $order,
        int $perPage,
        int $forPage
    ): ?IndexResult {
        if (!$this->canUseCategoryProjection($filters, $order)) {
            return null;
        }

        $categoryId = (int)$filters->pull('category_id')->values()[0];
        $excludedProductIds = [];

        if ($filters->has('product_id')) {
            $productFilter = $filters->pull('product_id');

            if (!$productFilter instanceof SetFilter || !$productFilter->exclude) {
                return null;
            }

            $excludedProductIds = $this->integerFilterValues($productFilter->values());
        }

        $brands = [];

        if ($filters->has('brand')) {
            $brandFilter = $filters->pull('brand');

            if (!$brandFilter instanceof SetFilter || $brandFilter->exclude) {
                return null;
            }

            $brands = $this->stringFilterValues($brandFilter->values());
        }

        if ($filters->count() > 0) {
            return null;
        }

        $skip = $perPage * ($forPage - 1);
        $query = $this->isProjectionPriceOrder($order)
            ? $this->categoryProjectionPriceQuery($index, $categoryId, $order, $excludedProductIds, $brands)
            : $this->categoryProjectionQuery($index, $categoryId, $order, $excludedProductIds, $brands);

        $slice = array_map(function ($item) {
            return $item->is_ghost ? 'product-' . $item->other_id : $item->id;
        }, $query->offset($skip)->limit($perPage)->get()->toArray());

        return new IndexResult($slice, $this->categoryProjectionCount($index, $categoryId, $excludedProductIds, $brands));
    }

    protected function categoryProjectionQuery(
        string $index,
        int $categoryId,
        SortOrder $order,
        array $excludedProductIds,
        array $brands = []
    )
    {
        $query = DB::table(self::CATEGORY_PROJECTION_TABLE . ' as c')
            ->select([
                'c.product_id as id',
                'c.variant_id as other_id',
                'c.is_ghost',
            ])
            ->where('c.index_name', $index)
            ->where('c.category_id', $categoryId);

        if ($excludedProductIds !== []) {
            $this->whereIntegerArrayNotContains($query, 'c.product_id', $excludedProductIds);
        }

        if ($brands !== []) {
            $query->whereIn('c.brand', $brands);
        }

        $this->handleProjectionOrder($order, $query, $categoryId);

        return $query;
    }

    protected function categoryProjectionPriceQuery(
        string $index,
        int $categoryId,
        SortOrder $order,
        array $excludedProductIds,
        array $brands = []
    )
    {
        [, $currencyCode] = explode('.', $order->property(), 2);
        $currencyCode = $this->priceProjectionCurrencyCode($index, $categoryId, $currencyCode);

        $query = DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE . ' as cp')
            ->join(self::CATEGORY_PROJECTION_TABLE . ' as c', function ($join) use ($categoryId): void {
                $join->on('c.index_id', '=', 'cp.index_id')
                    ->on('c.category_id', '=', 'cp.category_id')
                    ->on('c.index_name', '=', 'cp.index_name')
                    ->where('c.category_id', '=', $categoryId);
            })
            ->select([
                'c.product_id as id',
                'c.variant_id as other_id',
                'c.is_ghost',
            ])
            ->where('cp.index_name', $index)
            ->where('cp.category_id', $categoryId)
            ->where('cp.currency_code', $currencyCode);

        if ($excludedProductIds !== []) {
            $this->whereIntegerArrayNotContains($query, 'cp.product_id', $excludedProductIds);
        }

        if ($brands !== []) {
            $query->whereIn('c.brand', $brands);
        }

        return $query
            ->orderBy('cp.price', $this->sortDirection($order->direction()))
            ->orderBy('cp.index_id');
    }

    protected function priceProjectionCurrencyCode(string $index, int $categoryId, string $currencyCode): string
    {
        $defaultCurrencyCode = Currency::defaultCurrency()->code;

        if ($currencyCode === $defaultCurrencyCode) {
            return $currencyCode;
        }

        $hasActiveCurrencyPrices = DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where('category_id', $categoryId)
            ->where('currency_code', $currencyCode)
            ->exists();

        if ($hasActiveCurrencyPrices) {
            return $currencyCode;
        }

        $hasDefaultCurrencyPrices = DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where('category_id', $categoryId)
            ->where('currency_code', $defaultCurrencyCode)
            ->exists();

        if ($hasDefaultCurrencyPrices) {
            return $defaultCurrencyCode;
        }

        return (string)(DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where('category_id', $categoryId)
            ->orderBy('currency_code')
            ->value('currency_code') ?: $currencyCode);
    }

    protected function isProjectionPriceOrder(SortOrder $order): bool
    {
        return str_starts_with($order->property(), 'prices.');
    }

    protected function canUseCategoryProjection(Collection $filters, SortOrder $order): bool
    {
        if (!$this->hasCategoryReadProjection()) {
            return false;
        }

        if ($order instanceof Random || !$filters->has('category_id')) {
            return false;
        }

        $categoryFilter = $filters->get('category_id');

        if (!$categoryFilter instanceof SetFilter || $categoryFilter->exclude || count($categoryFilter->values()) !== 1) {
            return false;
        }

        $supportedProperties = [
            'sales_count',
            'reviews_rating',
            'created_at',
            'name',
            'stock',
        ];

        $property = $order->property();

        if (in_array($property, $supportedProperties, true)) {
            return true;
        }

        if (str_starts_with($property, 'sort_orders.') || str_starts_with($property, 'prices.')) {
            return true;
        }

        return false;
    }

    protected function handleProjectionOrder(SortOrder $order, $query, int $categoryId): void
    {
        $property = $order->property();
        $direction = $this->sortDirection($order->direction());

        if ($property === 'sales_count') {
            $query->orderBy('c.sales_count', $direction)->orderBy('c.index_id');
            return;
        }

        if ($property === 'reviews_rating') {
            $query->orderBy('c.reviews_rating', $direction)->orderBy('c.index_id');
            return;
        }

        if ($property === 'created_at') {
            $query->orderBy('c.created_at', $direction)->orderBy('c.index_id');
            return;
        }

        if ($property === 'name') {
            $query->orderBy('c.name', $direction)->orderBy('c.index_id');
            return;
        }

        if ($property === 'stock') {
            $query->orderBy('c.stock', $direction)->orderBy('c.index_id');
            return;
        }

        if (str_starts_with($property, 'sort_orders.')) {
            $query->orderBy('c.sort_order', $direction)->orderBy('c.index_id');
            return;
        }

        if (str_starts_with($property, 'prices.')) {
            [, $currencyCode] = explode('.', $property, 2);

            $query->leftJoin(self::CATEGORY_PRICE_PROJECTION_TABLE . ' as cp', function ($join) use ($categoryId, $currencyCode): void {
                $join->on('cp.index_id', '=', 'c.index_id')
                    ->where('cp.category_id', '=', $categoryId)
                    ->where('cp.currency_code', '=', $currencyCode);
            });

            $query->orderBy('cp.price', $direction)->orderBy('c.index_id');
            return;
        }

        $query->orderBy('c.index_id');
    }

    protected function categoryProjectionCount(string $index, int $categoryId, array $excludedProductIds, array $brands = []): int
    {
        $cacheKey = 'kodzero.posmall.index_category_count.' . md5(json_encode([
            $index,
            $categoryId,
            array_values($excludedProductIds),
            array_values($brands),
        ]));

        return (int)Cache::remember($cacheKey, 60, function () use ($index, $categoryId, $excludedProductIds, $brands): int {
            if ($excludedProductIds === [] && $brands === []) {
                return (int)DB::table(self::CATEGORY_STATS_PROJECTION_TABLE)
                    ->where('index_name', $index)
                    ->where('category_id', $categoryId)
                    ->value('total_count');
            }

            $query = DB::table(self::CATEGORY_PROJECTION_TABLE)
                ->where('index_name', $index)
                ->where('category_id', $categoryId);

            if ($brands !== []) {
                $query->whereIn('brand', $brands);
            }

            if ($excludedProductIds !== []) {
                $this->whereIntegerArrayNotContains($query, 'product_id', $excludedProductIds);
            }

            return $query->count();
        });
    }

    protected function fallbackIndexCount(string $index, Collection $filters, $countQuery): int
    {
        if (!$this->canCacheFallbackIndexCount($filters)) {
            return $countQuery->count();
        }

        $cacheKey = 'kodzero.posmall.index_fallback_count.' . md5(json_encode([
            'v2',
            $index,
            $this->fallbackCountFilterSignature($filters),
        ]));

        return (int)Cache::remember($cacheKey, 60, fn () => $countQuery->count());
    }

    protected function canCacheFallbackIndexCount(Collection $filters): bool
    {
        return $filters->every(fn ($filter): bool => $filter instanceof Filter);
    }

    protected function fallbackCountFilterSignature(Collection $filters): array
    {
        return $filters
            ->map(fn (Filter $filter, $key): array => [
                'key' => (string)$key,
                'class' => get_class($filter),
                'property' => $this->filterPropertySignature($filter->property),
                'exclude' => $filter instanceof SetFilter ? $filter->exclude : false,
                'values' => $this->filterValuesSignature($filter),
            ])
            ->sortBy('key')
            ->values()
            ->all();
    }

    protected function filterPropertySignature($property): array
    {
        if (is_object($property)) {
            return [
                'class' => get_class($property),
                'id' => $property->id ?? null,
                'slug' => $property->slug ?? null,
            ];
        }

        return [
            'value' => (string)$property,
        ];
    }

    protected function filterValuesSignature(Filter $filter): array
    {
        $values = $filter->values();

        if ($filter instanceof SetFilter) {
            $values = array_map('strval', $values);
            sort($values, SORT_STRING);
        }

        if ($filter instanceof RangeFilter) {
            ksort($values);
        }

        return $values;
    }

    protected function syncCategoryProjection(IndexEntry $indexEntry, string $index, array $indexData): void
    {
        if (!$this->hasCategoryWriteProjection()) {
            return;
        }

        $oldCategories = $this->deleteCategoryProjectionByIndexId((int)$indexEntry->id);

        if (!$indexEntry->published) {
            return;
        }

        $categories = $this->projectionArray($indexData['category_id'] ?? []);

        if ($categories === []) {
            return;
        }

        $sortOrders = $this->projectionArray($indexData['sort_orders'] ?? []);
        $prices = $this->projectionArray($indexData['prices'] ?? []);

        if ($prices === []) {
            $prices = $this->projectionArray($indexData['parent_prices'] ?? []);
        }

        $categoryRows = [];
        $priceRows = [];

        foreach ($categories as $categoryId) {
            $categoryId = (int)$categoryId;

            if ($categoryId < 1) {
                continue;
            }

            $categoryRows[] = [
                'index_id' => (int)$indexEntry->id,
                'category_id' => $categoryId,
                'product_id' => (int)$indexEntry->product_id,
                'variant_id' => $indexEntry->variant_id ? (int)$indexEntry->variant_id : null,
                'index_name' => $index,
                'name' => (string)$indexEntry->name,
                'brand' => (string)$indexEntry->brand,
                'published' => (bool)$indexEntry->published,
                'stock' => (int)$indexEntry->stock,
                'reviews_rating' => $indexEntry->reviews_rating,
                'sales_count' => (int)$indexEntry->sales_count,
                'on_sale' => (bool)$indexEntry->on_sale,
                'is_ghost' => (bool)$indexEntry->is_ghost,
                'sort_order' => isset($sortOrders[(string)$categoryId]) ? (int)$sortOrders[(string)$categoryId] : null,
                'created_at' => $indexEntry->created_at,
            ];

            foreach ($prices as $currencyCode => $price) {
                if (!is_numeric($price)) {
                    continue;
                }

                $priceRows[] = [
                    'index_id' => (int)$indexEntry->id,
                    'category_id' => $categoryId,
                    'product_id' => (int)$indexEntry->product_id,
                    'variant_id' => $indexEntry->variant_id ? (int)$indexEntry->variant_id : null,
                    'index_name' => $index,
                    'currency_code' => (string)$currencyCode,
                    'price' => (int)$price,
                ];
            }
        }

        if ($categoryRows !== []) {
            DB::table(self::CATEGORY_PROJECTION_TABLE)->insert($categoryRows);
        }

        if ($priceRows !== []) {
            DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)->insert($priceRows);
        }

        $this->refreshCategoryProjectionSummaries($index, array_merge($oldCategories, $categories));
    }

    protected function projectionArray($value): array
    {
        if ($value instanceof Collection) {
            return $value->all();
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function deleteCategoryProjectionByColumn(string $index, string $column, $id): void
    {
        if (!$this->hasCategoryWriteProjection()) {
            return;
        }

        $categoryIds = DB::table(self::CATEGORY_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where($column, $id)
            ->pluck('category_id')
            ->all();

        DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where($column, $id)
            ->delete();

        DB::table(self::CATEGORY_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->where($column, $id)
            ->delete();

        $this->refreshCategoryProjectionSummaries($index, $categoryIds);
    }

    protected function deleteCategoryProjectionByIndexId(int $indexId): array
    {
        $categoryRows = DB::table(self::CATEGORY_PROJECTION_TABLE)
            ->select('index_name', 'category_id')
            ->where('index_id', $indexId)
            ->get();

        DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
            ->where('index_id', $indexId)
            ->delete();

        DB::table(self::CATEGORY_PROJECTION_TABLE)
            ->where('index_id', $indexId)
            ->delete();

        $categoryRows
            ->groupBy('index_name')
            ->each(fn ($rows, $indexName) => $this->refreshCategoryProjectionSummaries($indexName, $rows->pluck('category_id')->all()));

        return $categoryRows->pluck('category_id')->all();
    }

    protected function refreshCategoryProjectionSummaries(string $index, array $categoryIds): void
    {
        if (!$this->hasCategorySummaryProjection()) {
            $this->clearCategoryProjectionCaches($index, $categoryIds);
            return;
        }

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        if ($categoryIds === []) {
            return;
        }

        DB::table(self::CATEGORY_STATS_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->whereIn('category_id', $categoryIds)
            ->delete();

        DB::table(self::CATEGORY_BRANDS_PROJECTION_TABLE)
            ->where('index_name', $index)
            ->whereIn('category_id', $categoryIds)
            ->delete();

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, total_count, updated_at)
select index_name, category_id, count(*)::integer, now()
from %2$s
where index_name = ?
  and category_id = any(?::int[])
group by index_name, category_id
SQL,
            $this->quoteIdentifier(self::CATEGORY_STATS_PROJECTION_TABLE),
            $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
        ), [$index, '{' . implode(',', $categoryIds) . '}']);

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, brand, updated_at)
select distinct index_name, category_id, brand, now()
from %2$s
where index_name = ?
  and category_id = any(?::int[])
  and brand <> ''
SQL,
            $this->quoteIdentifier(self::CATEGORY_BRANDS_PROJECTION_TABLE),
            $this->quoteIdentifier(self::CATEGORY_PROJECTION_TABLE)
        ), [$index, '{' . implode(',', $categoryIds) . '}']);

        $this->clearCategoryProjectionCaches($index, $categoryIds);
    }

    protected function clearCategoryProjectionCaches(string $index, array $categoryIds): void
    {
        foreach (array_unique(array_map('intval', $categoryIds)) as $categoryId) {
            Cache::forget('kodzero.posmall.index_category_count.' . md5(json_encode([$index, $categoryId, [], []])));
        }
    }

    protected function persist(string $index, Entry $entry)
    {
        $data = $entry->data();

        $productId = $index === 'products' ? $data['id'] : $data['product_id'];
        $variantId = $index === 'products' ? null : $data['id'];

        $isGhost = false;

        if (starts_with($variantId, 'product-')) {
            $isGhost = true;
            $productId = str_replace('product-', '', $variantId);
        }

        $indexData = [
            'name'                  => $data['name'] ?? '',
            'brand'                 => $data['brand']['slug'] ?? '',
            'stock'                 => $data['stock'],
            'reviews_rating'        => $data['reviews_rating'] ?? 0,
            'sales_count'           => $data['sales_count'] ?? 0,
            'on_sale'               => (bool)($data['on_sale'] ?? false),
            'published'             => (bool)($data['published'] ?? false),
            'category_id'           => $data['category_id'],
            'property_values'       => $data['property_values'],
            'sort_orders'           => $data['sort_orders'],
            'prices'                => $data['prices'],
            'parent_prices'         => $data['parent_prices'] ?? [],
            'customer_group_prices' => $data['customer_group_prices'] ?? [],
            'created_at'            => $data['created_at'] ?? now(),
        ];

        $customIndexData = Event::fire('posmall.index.extendData', [$data]);

        if (!empty($customIndexData) && is_array($customIndexData[0])) {
            $indexData = array_merge($indexData, $customIndexData[0]);
        }

        DB::transaction(function () use ($index, $productId, $variantId, $isGhost, $indexData): void {
            $indexEntry = $this->db()->updateOrCreate([
                'index'      => $index,
                'product_id' => $productId,
                'variant_id' => $isGhost ? null : $variantId,
                'is_ghost'   => $isGhost,
            ], $indexData);

            $this->syncCategoryProjection($indexEntry, $index, $indexData);
        });
    }

    protected function searchQuery(string $index, Collection $filters, SortOrder $order)
    {
        $idColumn = $index === 'products' ? 'product_id' : 'variant_id';
        $otherIdColumn = $idColumn === 'product_id' ? 'variant_id' : 'product_id';

        $query = DB::table($this->db()->table)->select([
            $idColumn . ' as id',
            $otherIdColumn . ' as other_id',
            'is_ghost',
        ]);

        $query
            ->where('index', $index)
            ->where('published', true);

        $filters = $this->applySpecialFilters($filters, $query);

        $this->applyCustomFilters($filters, $query);
        $this->handleOrder($order, $query);

        return $query;
    }

    protected function applySpecialFilters(Collection $filters, $query)
    {
        if ($filters->has('category_id')) {
            $filter = $filters->pull('category_id');

            $query->where(function ($q) use ($filter) {
                foreach ($filter->values() as $value) {
                    $this->orWhereJsonbContains($q, 'category_id', [(int)$value]);
                }
            });
        }

        foreach (['product_id', 'variant_id', 'brand'] as $property) {
            if ($filters->has($property)) {
                $this->applySpecialSetFilter($query, $filters->pull($property));
            }
        }

        if ($filters->has('on_sale')) {
            $filters->pull('on_sale');
            $query->where('on_sale', true);
        }

        if ($filters->has('in_stock')) {
            $filters->pull('in_stock');
            $query->where('stock', '>', 0);
        }

        if ($filters->has('price')) {
            $price = $filters->pull('price');
            $currency = Currency::activeCurrency()->code;

            ['min' => $min, 'max' => $max] = $price->values();

            $query->whereRaw('(prices->>?)::numeric >= ?', [
                $currency,
                (int)$min * 100,
            ]);

            $query->whereRaw('(prices->>?)::numeric <= ?', [
                $currency,
                (int)$max * 100,
            ]);
        }

        return $filters;
    }

    protected function applySpecialSetFilter($query, $filter)
    {
        $values = $filter->values();

        if ($filter->exclude) {
            if (in_array($filter->property, ['product_id', 'variant_id'], true)) {
                $this->whereIntegerArrayNotContains($query, $filter->property, $this->integerFilterValues($values));
                return;
            }

            $query->whereNotIn($filter->property, $values);
            return;
        }

        $query->whereIn($filter->property, $values);
    }

    protected function integerFilterValues(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int)$value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    protected function stringFilterValues(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => trim((string)$value))
            ->filter(fn (string $value) => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function whereIntegerArrayNotContains($query, string $columnSql, array $values): void
    {
        if ($values === []) {
            return;
        }

        $query->whereRaw($columnSql . ' <> ALL (?::int[])', [
            $this->postgresIntegerArrayLiteral($values),
        ]);
    }

    protected function whereIntegerArrayContains($query, string $columnSql, array $values): void
    {
        if ($values === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereRaw($columnSql . ' = ANY (?::int[])', [
            $this->postgresIntegerArrayLiteral($values),
        ]);
    }

    protected function postgresIntegerArrayLiteral(array $values): string
    {
        return '{' . implode(',', $this->integerFilterValues($values)) . '}';
    }

    protected function applyCustomFilters(Collection $filters, $query)
    {
        $filters->each(function (Filter $filter) use ($query) {
            if ($filter instanceof SetFilter) {
                $query->where(function ($q) use ($filter) {
                    $propertyId = (string)$filter->property->id;

                    foreach ($filter->values() as $value) {
                        $this->orWhereJsonbContains($q, 'property_values', [$propertyId => [$value]]);
                    }
                });
            }

            if ($filter instanceof RangeFilter) {
                $propertyId = (string)$filter->property->id;

                $query->whereRaw('(property_values->?->>0)::numeric >= ?', [
                    $propertyId,
                    $filter->minValue,
                ]);

                $query->whereRaw('(property_values->?->>0)::numeric <= ?', [
                    $propertyId,
                    $filter->maxValue,
                ]);
            }
        });
    }

    protected function handleOrder(SortOrder $order, $query)
    {
        if ($order instanceof Random) {
            $query->inRandomOrder();
            return;
        }

        $property = $order->property();
        $direction = $this->sortDirection($order->direction());

        if (!str_contains($property, '.')) {
            if (!$this->isSafeSortColumn($property)) {
                $query->orderBy('id', 'asc');
                return;
            }

            $query->orderBy($property, $direction)
                ->orderBy('id', 'asc');
            return;
        }

        [$field, $nested] = explode('.', $property, 2);

        if (!$this->isSafeJsonSort($field, $nested)) {
            $query->orderBy('id', 'asc');
            return;
        }

        if ($field === 'prices') {
            $query->orderByRaw(
                'COALESCE((prices->>?)::numeric, (parent_prices->>?)::numeric) ' . $direction . ' NULLS LAST',
                [$nested, $nested]
            )->orderBy('id', 'asc');
            return;
        }

        if ($field === 'sort_orders') {
            $query->orderByRaw(
                '(sort_orders->>?)::numeric ' . $direction . ' NULLS LAST',
                [$nested]
            )->orderBy('id', 'asc');
            return;
        }

        $query->orderByRaw(
            '(' . $field . '->>?) ' . $direction . ' NULLS LAST',
            [$nested]
        )->orderBy('id', 'asc');
    }

    protected function sortDirection(string $direction): string
    {
        return strtolower($direction) === 'desc' ? 'desc' : 'asc';
    }

    protected function isSafeSortColumn(string $column): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1;
    }

    protected function isSafeJsonSort(string $field, string $nested): bool
    {
        return in_array($field, self::JSON_SORT_FIELDS, true)
            && preg_match('/^[A-Za-z0-9_.:-]+$/', $nested) === 1;
    }

    protected function hasCategoryReadProjection(): bool
    {
        if (self::$categoryProjectionReady !== null) {
            return self::$categoryProjectionReady;
        }

        return self::$categoryProjectionReady = DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable(self::CATEGORY_PROJECTION_TABLE)
            && Schema::hasTable(self::CATEGORY_PRICE_PROJECTION_TABLE)
            && Schema::hasTable(self::CATEGORY_STATS_PROJECTION_TABLE);
    }

    protected function hasCategoryWriteProjection(): bool
    {
        if (self::$categoryWriteProjectionReady !== null) {
            return self::$categoryWriteProjectionReady;
        }

        return self::$categoryWriteProjectionReady = DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable(self::CATEGORY_PROJECTION_TABLE)
            && Schema::hasTable(self::CATEGORY_PRICE_PROJECTION_TABLE);
    }

    protected function hasCategorySummaryProjection(): bool
    {
        if (self::$categorySummaryProjectionReady !== null) {
            return self::$categorySummaryProjectionReady;
        }

        return self::$categorySummaryProjectionReady = DB::connection()->getDriverName() === 'pgsql'
            && Schema::hasTable(self::CATEGORY_STATS_PROJECTION_TABLE)
            && Schema::hasTable(self::CATEGORY_BRANDS_PROJECTION_TABLE);
    }

    protected function orWhereJsonbContains($query, string $column, array $value)
    {
        $sql = match ($column) {
            'category_id' => 'category_id @> ?::jsonb',
            'property_values' => 'property_values @> ?::jsonb',
            default => throw new \InvalidArgumentException(sprintf('Unsupported JSONB containment column [%s]', $column)),
        };

        // Keep the indexed JSONB column uncast so jsonb_path_ops GIN indexes can support @> containment.
        $query->orWhereRaw($sql, [
            json_encode($value),
        ]);
    }
}
