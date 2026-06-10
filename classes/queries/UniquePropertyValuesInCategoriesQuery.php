<?php

namespace KodZero\POSMall\Classes\Queries;

use DB;
use Illuminate\Support\Collection;
use October\Rain\Database\QueryBuilder;

/**
 * This query is used to get a list of all unique property values in one or
 * more categories. It is used to display a set of possible filters
 * for all available property values.
 *
 * @deprecated 3.4.0 use UniquePropertyValue::hydratePropertyValuesForCategories($categories)
 * @see \KodZero\POSMall\Models\UniquePropertyValue
 */
class UniquePropertyValuesInCategoriesQuery
{
    /**
     * An array of category ids.
     * @var Collection
     */
    protected $categories;

    public function __construct($categories)
    {
        $this->categories = $categories;
    }

    /**
     * Return a query to get all unique product property values.
     *
     * @return QueryBuilder
     */
    public function query()
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
            ->whereIn('kodzero_posmall_category_product.category_id', $this->categories->pluck('id'))
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
}
