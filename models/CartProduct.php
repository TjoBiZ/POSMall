<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use DB;
use Event;
use Model;
use KodZero\POSMall\Classes\Traits\Cart\CartItemPriceAccessors;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\Traits\JsonPrice;

class CartProduct extends Model
{
    use HashIds;
    use JsonPrice;
    use CartItemPriceAccessors;

    public $table = 'kodzero_posmall_cart_products';

    public $fillable = ['quantity', 'product_id', 'variant_id', 'weight', 'price', 'service_options_per_quantity'];

    public $jsonable = ['price'];

    public $hidden = [
        'id',
        'cart_id',
        'session_id',
        'customer_id',
        'shipping_address_id',
        'product_id',
        'variant_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public $appends = ['hashid'];

    public $casts = [
        'quantity'                     => 'integer',
        'id'                           => 'integer',
        'product_id'                   => 'integer',
        'variant_id'                   => 'integer',
        'service_options_per_quantity' => 'boolean',
    ];

    public $belongsTo = [
        'cart'    => Cart::class,
        'product' => Product::class,
        'variant' => Variant::class,
        'data'    => [Product::class, 'key' => 'product_id'],
    ];

    public $hasMany = [
        'custom_field_values' => [CustomFieldValue::class, 'key' => 'cart_product_id', 'otherKey' => 'id'],
    ];

    public $belongsToMany = [
        'service_options' => [
            ServiceOption::class,
            'table'    => 'kodzero_posmall_cart_product_service_option',
            'key'      => 'cart_product_id',
            'otherKey' => 'service_option_id',
        ],
    ];

    public static function boot()
    {
        parent::boot();
        static::saving(function (self $cartProduct) {
            $cartProduct->quantity = $cartProduct->data->normalizeQuantity($cartProduct->quantity);
        });
        static::created(function (self $cartProduct) {
            Event::fire('posmall.cart.product.added', [$cartProduct]);
        });
        static::updating(function (self $cartProduct) {
            Event::fire('posmall.cart.product.updating', [$cartProduct]);
        });
        static::updated(function (self $cartProduct) {
            Event::fire('posmall.cart.product.updated', [$cartProduct]);
        });
        static::deleted(function (self $cartProduct) {
            Event::fire('posmall.cart.product.removed', [$cartProduct]);
            CustomFieldValue::where('cart_product_id', $cartProduct->id)->delete();
            DB::table('kodzero_posmall_cart_product_service_option')->where('cart_product_id', $cartProduct->id)->delete();
        });
    }

    public function moveToOrder(Order $order)
    {
        DB::transaction(function () use ($order) {
            $this->reduceStock();

            $entry             = new OrderProduct();
            $entry->order_id   = $order->id;
            $entry->product_id = $this->product->id;
            $entry->variant_id = optional($this->variant)->id ?? null;

            $entry->item         = $this->item_data;
            $entry->name         = $this->variant ? $this->variant->name : $this->data->name;
            $entry->variant_name = optional($this->variant)->properties_description;
            $entry->quantity     = $this->quantity;
            $entry->is_virtual   = $this->product->is_virtual;

            $entry->taxes           = $this->filtered_product_taxes;
            $entry->tax_factor      = $this->productTaxFactor();
            $entry->service_options = $this->service_options->map(function (ServiceOption $option) {
                $data = $option->toArray();
                $data['quantity_multiplier'] = $this->serviceQuantityMultiplier;
                $data['service_options_per_quantity'] = $this->service_options_per_quantity !== false;

                return $data;
            })->toArray();

            // Set the attribute directly to prevent the price mutator from being triggered
            $quantity = max(1, (int)$this->quantity);
            $entry->attributes['price_post_taxes'] = $this->roundedCents($this->price()->integer);
            $entry->attributes['price_taxes']      = $this->roundedCents($this->getTotalTaxesAttribute() / $quantity);
            $entry->attributes['price_pre_taxes']  = $this->roundedCents($this->getPricePreTaxesAttribute());

            $entry->attributes['total_pre_taxes']  = $this->roundedCents($this->total_pre_taxes);
            $entry->attributes['total_taxes']      = $this->roundedCents($this->total_taxes);
            $entry->attributes['total_post_taxes'] = $this->roundedCents($this->total_post_taxes);

            $entry->weight       = $this->weight;
            $entry->total_weight = $this->total_weight;

            $entry->width     = $this->item->width;
            $entry->length    = $this->item->length;
            $entry->height    = $this->item->height;
            $entry->stackable = $this->item->stackable;
            $entry->shippable = $this->item->shippable;
            $entry->brand     = $this->item->brand ? $this->item->brand->toArray() : null;

            if ($this->variant) {
                $entry->properties_description = $this->variant->propertyValuesAsString();
                $entry->property_values        = $this->variant->property_values;
            }

            $entry->custom_field_values = $this->convertCustomFieldValues();
            $entry->save();
        });
    }

    private function roundedCents($value): int
    {
        return (int)round((float)$value);
    }

    /**
     * Converts the custom field values into a simpler structure
     * to save it with the order.
     */
    public function convertCustomFieldValues()
    {
        return $this->custom_field_values
            ->load(['custom_field', 'custom_field_option', 'custom_field_option.image'])
            ->map(function (CustomFieldValue $value) {
                $data                  = $value->toArray();
                $data['display_value'] = $value->displayValue;

                $prices = $value->priceForFieldOption($value->custom_field);

                $data['price'] = $prices->mapWithKeys(fn (Price $price) => [$price->currency->code => $price->float])->toArray();

                if (isset($data['custom_field']['custom_field_options'])) {
                    unset($data['custom_field']['custom_field_options']);
                }

                return $data;
            });
    }

    public function reduceStock()
    {
        return $this->item->reduceStock($this->quantity);
    }

    public function getItemAttribute()
    {
        return $this->variant ?? $this->product;
    }

    public function getPrefixedIdAttribute()
    {
        if ($this->variant) {
            return 'variant-' . $this->variant->id;
        }

        return 'product-' . $this->product->id;
    }

    public function getItemDataAttribute()
    {
        $model = $this->variant ?? $this->product;

        $data          = $model->attributesToArray();
        $data['price'] = $model->price;
        unset($data['description']);

        return $data;
    }

    public function getCustomFieldValueDescriptionAttribute()
    {
        return $this->custom_field_values->map(fn (CustomFieldValue $value) => sprintf('%s: %s', e($value->custom_field->name), $value->display_value))->implode('<br />');
    }

    public function getServiceQuantityMultiplierAttribute(): int
    {
        if ($this->service_options_per_quantity === false) {
            return 1;
        }

        return max(1, (int)$this->quantity);
    }
}
