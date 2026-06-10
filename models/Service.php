<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use DB;
use Cache;
use Model;
use October\Rain\Database\Traits\Sluggable;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Traits\SortableRelation;
use System\Models\File;

class Service extends Model
{
    use Validation;
    use Sluggable;
    use SortableRelation;
    use SoftDelete;

    public $table = 'kodzero_posmall_services';

    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];

    public $fillable = [
        'name',
        'description',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'gallery_autoplay_seconds',
        'sell_only_to_tax_states',
        'vendor_id',
        'channel_id',
    ];

    public $rules = [
        'name' => 'required',
        'gallery_autoplay_seconds' => 'nullable|numeric|min:0.5|max:30',
        'sell_only_to_tax_states' => 'nullable|boolean',
    ];

    public $casts = [
        'gallery_autoplay_seconds' => 'float',
        'sell_only_to_tax_states' => 'boolean',
        'vendor_id' => 'integer',
        'channel_id' => 'integer',
    ];

    public $hasMany = [
        'options' => [
            ServiceOption::class,
            'sort'     => 'sort_order ASC',
            'table'    => 'kodzero_posmall_service_options',
            'key'      => 'service_id',
            'otherKey' => 'id',
        ],
    ];

    public $attachMany = [
        'images' => [File::class, 'public' => true],
    ];

    public $belongsToMany = [
        'products' => [
            Product::class,
            'table'    => 'kodzero_posmall_product_service',
            'key'      => 'service_id',
            'otherKey' => 'product_id',
            'pivot'    => ['required'],
        ],
        'taxes'    => [
            Tax::class,
            'table'    => 'kodzero_posmall_service_tax',
            'key'      => 'service_id',
            'otherKey' => 'tax_id',
        ],
    ];

    public $belongsTo = [
        'vendor' => [Vendor::class],
        'channel' => [Channel::class],
    ];

    public $translatable = [
        'name',
        'description',
        'meta_title',
        'meta_keywords',
        'meta_description',
    ];

    public $slugs = [
        'code' => 'name',
    ];

    protected const STOREFRONT_AVAILABLE_CACHE_KEY = 'kodzero.posmall.services.storefront_available';

    public function scopeStorefrontAvailable($query)
    {
        return $query->whereHas('options');
    }

    public static function hasStorefrontServices(): bool
    {
        return (bool)Cache::rememberForever(
            self::STOREFRONT_AVAILABLE_CACHE_KEY,
            fn () => static::storefrontAvailable()->exists()
        );
    }

    public static function clearStorefrontAvailabilityCache(): void
    {
        Cache::forget(self::STOREFRONT_AVAILABLE_CACHE_KEY);
    }

    public function afterSave()
    {
        self::clearStorefrontAvailabilityCache();
    }

    public function afterDelete()
    {
        self::clearStorefrontAvailabilityCache();
        $this->options->each->delete();
        DB::table('kodzero_posmall_product_service')->where('service_id', $this->id)->delete();
    }
}
