<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Channel;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\Vendor;
use KodZero\POSMall\Models\Warehouse;
use KodZero\POSMall\Models\WarehouseInventory;

class DiscoveryApiService
{
    public function __construct(
        private readonly CatalogApiService $catalog
    ) {
    }

    public function brands(array $query): array
    {
        $paginator = Brand::withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage($query), ['*'], 'page', $this->page($query));

        return $this->paginated($paginator, 'brands', fn (Brand $brand) => $this->brand($brand));
    }

    public function brandBySlug(string $slug): array
    {
        return ['brand' => $this->brand(Brand::where('slug', $slug)->firstOrFail())];
    }

    public function brandProducts(string $slug, array $query, CommerceContext $context): array
    {
        $brand = Brand::where('slug', $slug)->firstOrFail();
        $query['brand'] = $brand->id;

        return ['brand' => $this->brand($brand)] + $this->catalog->list($query, $context);
    }

    public function properties(array $query): array
    {
        $category = $this->category($query['category'] ?? null);
        $values = $category
            ? Property::getValuesForCategory($this->categoryCollection($category, $this->boolean($query['include_children'] ?? true)))
            : collect();

        return [
            'category' => $category ? $this->categorySummary($category) : null,
            'properties' => Property::with('property_groups')
                ->orderBy('name')
                ->get()
                ->map(fn (Property $property) => $this->property($property, $values->get($property->id, collect())))
                ->values()
                ->all(),
        ];
    }

    public function vendors(array $query): array
    {
        $builder = Vendor::where('is_active', true);
        $this->applyAllowedIds($builder, $query['context'] ?? null, 'allowed_vendor_ids');

        $paginator = $builder
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($this->perPage($query), ['*'], 'page', $this->page($query));

        return $this->paginated($paginator, 'vendors', fn (Vendor $vendor) => $this->vendor($vendor));
    }

    public function vendorBySlug(string $slug, ?CommerceContext $context = null): array
    {
        $vendor = $this->activeVendor($slug);
        $this->authorizeContextId($context, 'vendor', $vendor->id);

        return ['vendor' => $this->vendor($vendor)];
    }

    public function vendorProducts(string $slug, array $query, CommerceContext $context): array
    {
        $vendor = $this->activeVendor($slug);
        $this->authorizeContextId($context, 'vendor', $vendor->id);
        $context = new CommerceContext(
            $context->token,
            $vendor,
            $context->channel,
            $context->warehouse,
            true,
            $context->channelExplicit,
            $context->warehouseExplicit
        );

        return ['vendor' => $this->vendor($vendor)] + $this->catalog->list($query, $context);
    }

    public function channels(array $query): array
    {
        $builder = Channel::where('is_active', true);
        $this->applyAllowedIds($builder, $query['context'] ?? null, 'allowed_channel_ids');

        $paginator = $builder
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($this->perPage($query), ['*'], 'page', $this->page($query));

        return $this->paginated($paginator, 'channels', fn (Channel $channel) => $this->channel($channel));
    }

    public function channelBySlug(string $slug, ?CommerceContext $context = null): array
    {
        $channel = Channel::where('is_active', true)->where('slug', $slug)->firstOrFail();
        $this->authorizeContextId($context, 'channel', $channel->id);

        return ['channel' => $this->channel($channel)];
    }

    public function warehouses(array $query): array
    {
        $builder = Warehouse::where('is_active', true);
        $this->applyAllowedIds($builder, $query['context'] ?? null, 'allowed_warehouse_ids');

        $paginator = $builder
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($this->perPage($query), ['*'], 'page', $this->page($query));

        return $this->paginated($paginator, 'warehouses', fn (Warehouse $warehouse) => $this->warehouse($warehouse));
    }

    public function warehouseBySlug(string $slug, ?CommerceContext $context = null): array
    {
        $warehouse = Warehouse::where('is_active', true)->where('slug', $slug)->firstOrFail();
        $this->authorizeContextId($context, 'warehouse', $warehouse->id);

        return [
            'warehouse' => $this->warehouse($warehouse) + [
                'stock_items' => WarehouseInventory::where('warehouse_id', $warehouse->id)->count(),
                'stock_total' => (int)WarehouseInventory::where('warehouse_id', $warehouse->id)->sum('stock'),
            ],
        ];
    }

    public function warehouseStock(string $slug, array $query, ?CommerceContext $context = null): array
    {
        $warehouse = Warehouse::where('is_active', true)->where('slug', $slug)->firstOrFail();
        $this->authorizeContextId($context, 'warehouse', $warehouse->id);
        $paginator = WarehouseInventory::with(['product', 'variant'])
            ->where('warehouse_id', $warehouse->id)
            ->where('stock', '>', 0)
            ->orderBy('product_id')
            ->orderBy('variant_id')
            ->paginate($this->perPage($query), ['*'], 'page', $this->page($query));

        return $this->paginated($paginator, 'stock', fn (WarehouseInventory $item) => [
            'stock' => (int)$item->stock,
            'product' => $item->product ? [
                'id' => (int)$item->product->id,
                'public_id' => (string)$item->product->prefixed_id,
                'name' => (string)$item->product->name,
                'slug' => (string)$item->product->slug,
                'type' => $item->product->is_virtual ? 'virtual_product' : 'product',
            ] : null,
            'variant' => $item->variant ? [
                'id' => (int)$item->variant->id,
                'public_id' => (string)$item->variant->prefixed_id,
                'name' => (string)$item->variant->name,
            ] : null,
        ]) + [
            'warehouse' => $this->warehouse($warehouse),
        ];
    }

    private function brand(Brand $brand): array
    {
        return [
            'id' => (int)$brand->id,
            'name' => (string)$brand->name,
            'slug' => (string)$brand->slug,
            'description' => (string)$brand->description,
            'website' => (string)$brand->website,
            'products_count' => (int)($brand->products_count ?? 0),
        ];
    }

    private function property(Property $property, Collection $values): array
    {
        return [
            'id' => (int)$property->id,
            'hash_id' => (string)$property->hash_id,
            'name' => (string)$property->name,
            'slug' => (string)$property->slug,
            'type' => (string)$property->type,
            'unit' => (string)$property->unit,
            'options' => $property->options ?: [],
            'values' => $values->map(fn ($value) => [
                'value' => $value->value,
                'display_value' => (string)$value->display_value,
            ])->values()->all(),
        ];
    }

    private function vendor(Vendor $vendor): array
    {
        return [
            'id' => (int)$vendor->id,
            'name' => (string)$vendor->name,
            'slug' => (string)$vendor->slug,
            'is_default' => (bool)$vendor->is_default,
        ];
    }

    private function channel(Channel $channel): array
    {
        return [
            'id' => (int)$channel->id,
            'name' => (string)$channel->name,
            'slug' => (string)$channel->slug,
            'type' => (string)$channel->type,
            'is_default' => (bool)$channel->is_default,
        ];
    }

    private function warehouse(Warehouse $warehouse): array
    {
        return [
            'id' => (int)$warehouse->id,
            'name' => (string)$warehouse->name,
            'slug' => (string)$warehouse->slug,
            'type' => (string)$warehouse->type,
            'is_default' => (bool)$warehouse->is_default,
        ];
    }

    private function activeVendor(string $slug): Vendor
    {
        return Vendor::where('is_active', true)->where('slug', $slug)->firstOrFail();
    }

    private function applyAllowedIds($query, ?CommerceContext $context, string $attribute): void
    {
        $ids = $this->allowedIds($context, $attribute);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
    }

    private function authorizeContextId(?CommerceContext $context, string $type, int $id): void
    {
        if (!$context) {
            return;
        }

        $allowed = match ($type) {
            'vendor' => $context->token->allowsVendorId($id),
            'channel' => $context->token->allowsChannelId($id),
            'warehouse' => $context->token->allowsWarehouseId($id),
            default => true,
        };

        if (!$allowed) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this ' . $type . '.');
        }
    }

    private function allowedIds(?CommerceContext $context, string $attribute): array
    {
        if (!$context) {
            return [];
        }

        return collect((array)($context->token->{$attribute} ?: []))
            ->map(fn ($id) => (int)$id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function category(mixed $value): ?Category
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return ctype_digit($value)
            ? Category::findOrFail((int)$value)
            : Category::getByNestedSlug($value);
    }

    private function categoryCollection(Category $category, bool $includeChildren)
    {
        return $includeChildren ? $category->getAllChildrenAndSelf() : collect([$category]);
    }

    private function categorySummary(Category $category): array
    {
        return [
            'id' => (int)$category->id,
            'name' => (string)$category->name,
            'slug' => (string)$category->nested_slug,
        ];
    }

    private function paginated(LengthAwarePaginator $paginator, string $key, callable $mapper): array
    {
        return [
            $key => $paginator->getCollection()->map($mapper)->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    private function perPage(array $query): int
    {
        return max(1, min(100, (int)($query['per_page'] ?? 24)));
    }

    private function page(array $query): int
    {
        return max(1, (int)($query['page'] ?? 1));
    }

    private function boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
