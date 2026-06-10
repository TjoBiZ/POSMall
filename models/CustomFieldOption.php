<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\Sortable;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\Traits\PriceAccessors;
use System\Models\File;

class CustomFieldOption extends Model
{
    use Validation;
    use Sortable;
    use HashIds;
    use PriceAccessors;

    public const MORPH_KEY = 'posmall.custom_field_option';

    public $implement = ['@RainLab.Translate.Behaviors.TranslatableModel'];

    public $translatable = ['name'];

    public $with = ['prices'];

    public $fillable = [
        'id',
        'name',
        'sort_order',
        'option_value',
        'custom_field_id',
    ];

    public $rules = [
        'name' => 'required',
    ];

    public $attachOne = [
        'image' => File::class,
    ];

    public $belongsTo = [
        'product'      => Product::class,
        'custom_field' => CustomField::class,
    ];

    public $morphMany = [
        'prices' => [Price::class, 'name' => 'priceable', 'conditions' => 'price_category_id is null'],
    ];

    /**
     * The parent's field type is store to make trigger conditions
     * work in the custom backend relationship form.
     *
     * @var string
     */
    public $field_type = '';

    public $table = 'kodzero_posmall_custom_field_options';

    public function afterDelete()
    {
        $this->prices()->delete();
    }

    public function getSafeColorValueAttribute(): string
    {
        return self::sanitizeColorValue((string)$this->option_value);
    }

    public static function sanitizeColorValue(string $value): string
    {
        $value = trim($value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : 'transparent';
    }
}
