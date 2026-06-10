<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use KodZero\POSMall\Classes\CategoryFilter\QueryString;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;

class CatalogApiService
{
    public function __construct(
        private readonly Index $index,
        private readonly ProductResource $products,
        private readonly ApiCatalogCache $cache
    ) {
    }

    public function list(array $query, ?CommerceContext $context = null): array
    {
        return $this->cache->remember(
            'products.' . md5(json_encode([$this->cacheableQuery($query), $this->contextKey($context), Currency::activeCurrency()->code])),
            ApiSettings::catalogCacheSeconds(),
            fn () => $this->listUncached($query, $context)
        );
    }

    private function listUncached(array $query, ?CommerceContext $context = null): array
    {
        $perPage = min(100, max(1, (int)($query['per_page'] ?? 24)));
        $page = max(1, (int)($query['page'] ?? 1));
        $includeVariants = filter_var($query['include_variants'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $includeChildren = filter_var($query['include_children'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $category = $this->resolveCategory($query['category'] ?? null);
        $filters = $this->filters($query, $category, $includeChildren, $context);
        $sort = SortOrder::fromKey((string)($query['sort'] ?? SortOrder::default()));
        $sort->setFilters(clone $filters);
        $indexName = $includeVariants ? 'variants' : 'products';

        $result = $this->index->fetch($indexName, $filters, $sort, $perPage, $page);
        $items = $this->hydrateIndexResult($result->ids, $includeVariants);
        $paginator = new LengthAwarePaginator($items, $result->totalCount, $perPage, $page);

        return [
            'items' => $items->map(fn (Product|Variant $item) => $this->products->listingItem($item))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'sort' => $sort->key(),
            'category' => $category ? $this->categorySummary($category) : null,
        ];
    }

    public function categories(): array
    {
        $byParent = Category::query()
            ->orderBy('nest_left')
            ->get()
            ->groupBy(fn (Category $category) => (int)($category->parent_id ?: 0));

        $build = function (int $parentId) use (&$build, $byParent): array {
            return ($byParent->get($parentId, collect()))
                ->sortBy('nest_left')
                ->map(fn (Category $category) => $this->categorySummary($category) + [
                    'children' => $build((int)$category->id),
                ])
                ->values()
                ->all();
        };

        return $build(0);
    }

    public function product(string $slug, ?CommerceContext $context = null): array
    {
        return $this->cache->remember(
            'product.' . md5(json_encode([$slug, $this->contextKey($context), Currency::activeCurrency()->code])),
            ApiSettings::catalogCacheSeconds(),
            fn () => $this->productUncached($slug, $context)
        );
    }

    private function productUncached(string $slug, ?CommerceContext $context = null): array
    {
        $query = Product::published()
            ->withoutServiceCarriers()
            ->where('slug', $slug)
            ->with([
                'brand',
                'categories',
                'image_sets.images',
                'prices.currency',
                'variants.prices.currency',
                'variants.image_sets.images',
                'services.options.prices.currency',
            ]);

        $ids = $this->contextProductIds($context);
        if ($ids !== null) {
            $query->whereIn('id', $ids ?: [0]);
        }

        $product = $query->firstOrFail();

        return $this->products->detail($product);
    }

    private function cacheableQuery(array $query): array
    {
        ksort($query);

        return $query;
    }

    private function contextKey(?CommerceContext $context): array
    {
        return [
            'vendor' => optional($context?->vendor)->id,
            'channel' => optional($context?->channel)->id,
            'warehouse' => optional($context?->warehouse)->id,
        ];
    }

    private function filters(array $query, ?Category $category, bool $includeChildren, ?CommerceContext $context): Collection
    {
        $query = collect($query)->except([
            'page',
            'per_page',
            'sort',
            'include_variants',
            'include_children',
            'category',
        ])->all();

        $filters = (new QueryString())->deserialize($query, $category);

        if ($category && !$filters->has('category_id')) {
            $categories = $includeChildren ? $category->getAllChildrenAndSelf() : collect([$category]);
            $filters->put('category_id', new SetFilter('category_id', $categories->pluck('id')->all()));
        }

        $this->excludeServiceCarrierProducts($filters);
        $this->applyCommerceContext($filters, $context);

        return $filters;
    }

    private function applyCommerceContext(Collection $filters, ?CommerceContext $context): void
    {
        $ids = $this->contextProductIds($context);

        if ($ids !== null) {
            $filters->put('product_id', new SetFilter('product_id', $ids ?: [0]));
        }
    }

    public function contextProductIds(?CommerceContext $context): ?array
    {
        if (!$context) {
            return null;
        }

        $sets = [];

        if ($context->vendor && DB::table('kodzero_posmall_product_vendor')->where('vendor_id', $context->vendor->id)->exists()) {
            $sets[] = DB::table('kodzero_posmall_product_vendor')
                ->where('vendor_id', $context->vendor->id)
                ->pluck('product_id')
                ->map(fn ($id) => (int)$id)
                ->all();
        }

        if ($context->channel && DB::table('kodzero_posmall_product_channel')->where('channel_id', $context->channel->id)->exists()) {
            $sets[] = DB::table('kodzero_posmall_product_channel')
                ->where('channel_id', $context->channel->id)
                ->pluck('product_id')
                ->map(fn ($id) => (int)$id)
                ->all();
        }

        if ($context->warehouse && DB::table('kodzero_posmall_warehouse_inventory')->where('warehouse_id', $context->warehouse->id)->exists()) {
            $sets[] = DB::table('kodzero_posmall_warehouse_inventory')
                ->where('warehouse_id', $context->warehouse->id)
                ->where('stock', '>', 0)
                ->pluck('product_id')
                ->map(fn ($id) => (int)$id)
                ->all();
        }

        if ($sets === []) {
            return null;
        }

        return array_values(array_diff(
            array_unique(array_intersect(...$sets)),
            $this->serviceCarrierProductIds()
        ));
    }

    private function hydrateIndexResult(array $ids, bool $includeVariants): Collection
    {
        $model = $includeVariants ? new Variant() : new Product();
        $numericIds = array_map('intval', array_filter($ids, 'is_numeric'));
        $ghostIds = array_values(array_diff($ids, $numericIds));

        $models = $model->with($this->listingIncludes($includeVariants))->find($numericIds);
        $ghosts = $this->ghostProducts($ghostIds);

        return collect($ids)
            ->map(fn ($id) => is_numeric($id)
                ? $models->find((int)$id)
                : $ghosts->find((int)str_replace('product-', '', (string)$id)))
            ->filter()
            ->reject(fn ($item) => $this->isServiceCarrierProduct($item))
            ->values();
    }

    private function ghostProducts(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $ids = collect($ids)
            ->map(fn ($id) => (int)str_replace('product-', '', (string)$id))
            ->filter()
            ->values()
            ->all();

        return Product::with($this->listingIncludes(false))->find($ids);
    }

    private function listingIncludes(bool $includeVariants): array
    {
        $includes = [
            'image_sets.images',
            'prices.currency',
            'customer_group_prices',
            'additional_prices.currency',
        ];

        if ($includeVariants) {
            $includes[] = 'product.image_sets.images';
            $includes[] = 'product.prices.currency';
        } else {
            $includes[] = 'variants';
        }

        return $includes;
    }

    private function excludeServiceCarrierProducts(Collection $filters): void
    {
        if ($filters->has('product_id')) {
            return;
        }

        $ids = $this->serviceCarrierProductIds();

        if ($ids !== []) {
            $filters->put('product_id', new SetFilter('product_id', $ids, true));
        }
    }

    private function serviceCarrierProductIds(): array
    {
        return Product::published()
            ->serviceCarriers()
            ->pluck('id')
            ->map(fn ($id) => (int)$id)
            ->all();
    }

    private function resolveCategory($value): ?Category
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return Category::findOrFail((int)$value);
        }

        return Category::getByNestedSlug($value);
    }

    private function categorySummary(Category $category): array
    {
        return [
            'id' => (int)$category->id,
            'name' => (string)$category->name,
            'slug' => (string)$category->nested_slug,
            'description_short' => (string)$category->description_short,
        ];
    }

    private function isServiceCarrierProduct(Product|Variant $item): bool
    {
        $product = $item instanceof Variant ? $item->product : $item;
        $sku = (string)$product->user_defined_id;

        return str_starts_with($sku, 'POSMALL-SERVICE-CARRIER-');
    }
}
