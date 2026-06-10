<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Api\ApiCatalogCache;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;

class WarehouseInventory extends Model
{
    use Validation;

    public $table = 'kodzero_posmall_warehouse_inventory';

    public $rules = [
        'warehouse_id' => 'required|integer|exists:kodzero_posmall_warehouses,id',
        'product_id' => 'required|integer|exists:kodzero_posmall_products,id',
        'variant_id' => 'nullable|integer|exists:kodzero_posmall_product_variants,id',
        'stock' => 'required|integer|min:0',
    ];

    public $fillable = [
        'warehouse_id',
        'product_id',
        'variant_id',
        'stock',
    ];

    public $belongsTo = [
        'warehouse' => [Warehouse::class],
        'product' => [Product::class],
        'variant' => [Variant::class],
    ];

    public $casts = [
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'variant_id' => 'integer',
        'stock' => 'integer',
    ];

    public function afterSave(): void
    {
        app(ApiCatalogCache::class)->flush();
        PublicStorefrontCache::bumpVersion();
    }

    public function afterDelete(): void
    {
        app(ApiCatalogCache::class)->flush();
        PublicStorefrontCache::bumpVersion();
    }
}
