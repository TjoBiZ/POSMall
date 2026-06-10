<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Cache;
use DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Model;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Jobs\UpdateUniquePropertyForCategory;
use Queue;

class UniquePropertyValue extends Model
{
    use Validation;

    /**
     * @var string table name
     */
    public $table = 'kodzero_posmall_unique_property_values';

    /**
     * @var array rules for validation
     */
    public $rules = [
        'property_value_id' => 'required|exists:kodzero_posmall_property_values,id',
        'property_id' => 'required|exists:kodzero_posmall_properties,id',
        'category_id' => 'required|exists:kodzero_posmall_categories,id',
        'value' => 'required',
        'index_value' => 'required',
    ];

    public $timestamps = false;

    public $fillable = [
        'property_value_id',
        'property_id',
        'value',
        'index_value',
        'category_id',
    ];

    public $belongsTo = [
        'property' => [Property::class, 'deleted' => true],
        'property_value' => [PropertyValue::class, 'deleted' => true],
        'category'  => [Category::class],
    ];

    /**
     * Get property values for provided categories without duplicates
     *
     * @param Collection $categories
     * @return Collection
     */
    public static function hydratePropertyValuesForCategories(Collection $categories): Collection
    {
        $raw = self::selectRaw('MIN(property_value_id) as id, value, index_value, property_id')
            ->when($categories->count() > 0, function ($q) use ($categories) {
                $q->whereIn('category_id', $categories->pluck('id'));
            })
            ->groupBy('value', 'index_value', 'property_id')
            ->get()
            ->toArray();

        return PropertyValue::hydrate($raw);
    }

    /**
     * Reset unique properties for provided category
     *
     * @param Category $category
     * @return void
     */
    public static function resetForCategory(Category $category): void
    {
        $records = self::getRawQueryForCategory($category)->get();

        DB::transaction(function () use ($category, $records) {
            $idsToLeave = [];

            foreach ($records as $record) {
                $uniquePropertyValue = self::where('property_id', $record->property_id)
                    ->where('category_id', $category->id)
                    ->where('value', $record->value)
                    ->where('index_value', $record->index_value)
                    ->first();

                if (!$uniquePropertyValue) {
                    $uniquePropertyValue = new UniquePropertyValue();
                    $uniquePropertyValue->property_id = $record->property_id;
                    $uniquePropertyValue->property_value_id = $record->id;
                    $uniquePropertyValue->category_id = $category->id;
                    $uniquePropertyValue->value = $record->value;
                    $uniquePropertyValue->index_value = $record->index_value;
                    $uniquePropertyValue->save();
                }

                $idsToLeave[] = $uniquePropertyValue->id;
            }

            // Cleanup all unique properties that were already deleted from the category
            $uniquePropertiesToRemove = self::where('category_id', $category->id)
                ->whereNotIn('id', $idsToLeave)
                ->get();

            foreach ($uniquePropertiesToRemove as $uniquePropertyToRemove) {
                $uniquePropertyToRemove->delete();
            }
        });
    }

    /**
     * Update unique property values using category
     *
     * @param Category $category
     * @return void
     */
    public static function updateUsingCategory(Category $category): void
    {
        $key = self::getCacheKeyForCategory($category);

        if (Cache::has($key)) {
            // The category has already pending queue job
            return;
        }

        Cache::forever($key, true);
        Queue::push(UpdateUniquePropertyForCategory::class, ['id' => $category->id]);
    }

    /**
     * Update unique property values using product
     *
     * @param Product $product
     * @return void
     */
    public static function updateUsingProduct(Product $product): void
    {
        $product->loadMissing(['categories']);

        foreach ($product->categories as $category) {
            self::updateUsingCategory($category);
        }
    }

    /**
     * Update unique property values using property group
     *
     * @param PropertyGroup $propertyGroup
     * @return void
     */
    public static function updateUsingPropertyGroup(PropertyGroup $propertyGroup): void
    {
        $propertyGroup->loadMissing(['categories']);

        foreach ($propertyGroup->categories as $category) {
            self::updateUsingCategory($category);
        }
    }

    /**
     * Update unique property values using property
     *
     * @param Property $property
     * @return void
     */
    public static function updateUsingProperty(Property $property): void
    {
        $property->loadMissing(['property_groups.categories']);

        foreach ($property->property_groups as $propertyGroup) {
            self::updateUsingPropertyGroup($propertyGroup);
        }
    }

    /**
     * Update unique property values using property value
     *
     * @param PropertyValue $propertyValue
     * @return void
     */
    public static function updateUsingPropertyValue(PropertyValue $propertyValue): void
    {
        $propertyValue->loadMissing(['product.categories']);
        $product = $propertyValue->product;

        if (!$product) {
            $product = Product::with(['categories'])->where('id', $propertyValue->product_id)->first();
        }

        if ($product) {
            self::updateUsingProduct($product);
        }
    }

    /**
     * Get unique properties for category query
     *
     * @param Category $category
     * @return Builder
     */
    public static function getRawQueryForCategory(Category $category): Builder
    {
        return DB::table('kodzero_posmall_products')
            ->selectRaw(
                '
                MIN(kodzero_posmall_property_values.id) AS id,
                kodzero_posmall_property_values.value,
                kodzero_posmall_property_values.index_value,
                kodzero_posmall_property_values.property_id'
            )
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('kodzero_posmall_products.published', true)
                        ->whereNull('kodzero_posmall_product_variants.id');
                })->orWhere('kodzero_posmall_product_variants.published', true);
            })
            ->where('kodzero_posmall_category_product.category_id', $category->id)
            ->whereNull('kodzero_posmall_product_variants.deleted_at')
            ->whereNull('kodzero_posmall_products.deleted_at')
            ->where('kodzero_posmall_property_values.value', '<>', '')
            ->whereNotNull('kodzero_posmall_property_values.value')
            ->groupBy(
                'kodzero_posmall_property_values.value',
                'kodzero_posmall_property_values.index_value',
                'kodzero_posmall_property_values.property_id'
            )
            ->leftJoin(
                'kodzero_posmall_product_variants',
                'kodzero_posmall_products.id',
                '=',
                'kodzero_posmall_product_variants.product_id'
            )
            ->leftJoin(
                'kodzero_posmall_category_product',
                'kodzero_posmall_products.id',
                '=',
                'kodzero_posmall_category_product.product_id'
            )
            ->join(
                'kodzero_posmall_property_values',
                'kodzero_posmall_products.id',
                '=',
                'kodzero_posmall_property_values.product_id'
            );
    }

    public static function getCacheKeyForCategory(Category $category): string
    {
        return 'update-unique-property-value-for-category-' . $category->id;
    }
}
