<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Carbon\Carbon;
use Model;
use October\Rain\Database\Traits\Nullable;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\Traits\PriceAccessors;

class Discount extends Model
{
    use Validation;
    use PriceAccessors;
    use Nullable;
    use HashIds;

    public const MORPH_KEY = 'posmall.discount';

    public $rules = [
        'name'                                 => 'required',
        'valid_from'                           => 'nullable|date',
        'expires'                              => 'nullable|date',
        'number_of_usages'                     => 'bail|nullable|integer|min:0',
        'max_number_of_usages'                 => 'bail|nullable|integer|min:0',
        'trigger'                              => 'in:total,code,product,customer_group,shipping_method,payment_method',
        'types'                                => 'in:fixed_amount,rate,shipping',
        'product'                              => 'required_if:trigger,product',
        'customer_group'                       => 'required_if:trigger,customer_group',
        'code'                                 => 'nullable|unique:kodzero_posmall_discounts,code',
        'type'                                 => 'in:fixed_amount,rate,shipping',
        'rate'                                 => 'bail|required_if:type,rate|nullable|numeric|min:0|max:100',
        'shipping_description'                 => 'required_if:type,shipping',
        'shipping_guaranteed_days_to_delivery' => 'bail|nullable|integer|min:0',
    ];

    public $with = ['shipping_prices', 'amounts', 'totals_to_reach'];

    public $table = 'kodzero_posmall_discounts';

    public $dates = ['valid_from', 'expires'];

    public $nullable = ['max_number_of_usages'];

    public $casts = [
        'number_of_usages' => 'integer',
    ];

    public $morphMany = [
        'shipping_prices' => [
            Price::class,
            'name' => 'priceable',
            'conditions' => "field = 'shipping_prices'",
        ],
        'amounts' => [
            Price::class,
            'name' => 'priceable',
            'conditions' => "field = 'amounts'",
        ],
        'totals_to_reach' => [
            Price::class,
            'name' => 'priceable',
            'conditions' => "field = 'totals_to_reach'",
        ],
    ];

    public $fillable = [
        'name',
        'valid_from',
        'expires',
        'number_of_usages',
        'max_number_of_usages',
        'trigger',
        'types',
        'product',
        'product_id',
        'customer_group',
        'type',
        'rate',
        'code',
        'shipping_description',
        'shipping_guaranteed_days_to_delivery',
    ];

    public $belongsTo = [
        'product' => [Product::class],
        'customer_group' => [CustomerGroup::class],
        'payment_method' => [PaymentMethod::class],
    ];

    public $belongsToMany = [
        'carts' => [Cart::class, 'table' => 'kodzero_posmall_cart_discount'],
        'shipping_methods' => [ShippingMethod::class, 'table' => 'kodzero_posmall_shipping_method_discount'],
    ];

    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];

    public $translatable = [
        'name',
        'shipping_description',
    ];

    public static function boot()
    {
        parent::boot();
        static::saving(function (self $discount) {
            if ($discount->trigger === 'code' && ! $discount->code) {
                $discount->code = strtoupper(str_random(10));
            }
        });
        static::saving(function (self $discount) {
            $discount->code = strtoupper($discount->code ?? '');

            if ($discount->trigger !== 'product') {
                $discount->product_id = null;
            }

            if ($discount->trigger !== 'code') {
                $discount->code = null;
            }

            if ($discount->trigger !== 'customer_group') {
                $discount->customer_group_id = null;
            }
        });
    }

    /**
     * Filter out discounts that are valid and not expired.
     * @param mixed $q
     */
    public function scopeIsActive($q)
    {
        $q->where(function ($q) {
            $q->where(function ($q) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', Carbon::now());
            })->where(function ($q) {
                $q->whereNull('expires')->orWhere('expires', '>', Carbon::now());
            });
        });
    }

    public function getTypeOptions()
    {
        $keys = [
            'fixed_amount',
            'rate',
            'shipping',
        ];

        return collect($keys)->mapWithKeys(fn ($key) => [$key => trans('kodzero.posmall::lang.discounts.types.' . $key)]);
    }

    public function getTriggerOptions()
    {
        $keys = [
            'total',
            'code',
            'product',
            'shipping_method',
            'customer_group',
            'payment_method',
        ];

        return collect($keys)->mapWithKeys(fn ($key) => [$key => trans('kodzero.posmall::lang.discounts.triggers.' . $key)]);
    }

    public function amount($currency = null)
    {
        return $this->price($currency, 'amounts');
    }

    public function totalToReach($currency = null)
    {
        return $this->price($currency, 'totals_to_reach');
    }

    public function shippingPrice($currency = null)
    {
        return $this->price($currency, 'shipping_prices');
    }

    public function getProductIdOptions()
    {
        return [null => trans('kodzero.posmall::lang.common.none')] + Product::get()->pluck('name', 'id')->toArray();
    }
}
