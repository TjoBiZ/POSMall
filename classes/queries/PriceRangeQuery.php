<?php

namespace KodZero\POSMall\Classes\Queries;

use DB;
use Illuminate\Support\Collection;
use October\Rain\Database\QueryBuilder;
use KodZero\POSMall\Models\Currency;
use Schema;

/**
 * This query fetches the max and min prices of a category.
 */
class PriceRangeQuery
{
    private const CATEGORY_PRICE_PROJECTION_TABLE = 'kodzero_posmall_index_category_prices';

    private static ?bool $categoryPriceProjectionReady = null;

    /**
     * The currently active Currency.
     *
     * @var Currency
     */
    protected $currency;

    /**
     * Categories to filter by.
     *
     * @var Collection
     */
    protected $categories;

    public function __construct(Collection $categories, Currency $currency)
    {
        $this->currency   = $currency;
        $this->categories = $categories;
    }

    /**
     * Return the query to filter the max and min price values.
     *
     * @return QueryBuilder
     */
    public function query()
    {
        if ($this->canUseCategoryPriceProjection()) {
            return DB::table(self::CATEGORY_PRICE_PROJECTION_TABLE)
                ->selectRaw(DB::raw('min(price) as min, max(price) as max'))
                ->where('index_name', 'products')
                ->whereIn('category_id', $this->categoryIds())
                ->where('currency_code', $this->currency->code);
        }

        return DB::table('kodzero_posmall_product_prices')
            ->selectRaw(DB::raw('min(price) as min, max(price) as max'))
            ->join(
                'kodzero_posmall_products',
                'kodzero_posmall_product_prices.product_id',
                '=',
                'kodzero_posmall_products.id'
            )
            ->join(
                'kodzero_posmall_category_product',
                'kodzero_posmall_product_prices.product_id',
                '=',
                'kodzero_posmall_category_product.product_id'
            )
            ->whereIn('kodzero_posmall_category_product.category_id', $this->categories->pluck('id'))
            ->where('kodzero_posmall_products.published', true)
            ->whereNull('kodzero_posmall_products.deleted_at')
            ->where('kodzero_posmall_product_prices.currency_id', $this->currency->id);
    }

    protected function canUseCategoryPriceProjection(): bool
    {
        if (self::$categoryPriceProjectionReady === null) {
            self::$categoryPriceProjectionReady = DB::connection()->getDriverName() === 'pgsql'
                && Schema::hasTable(self::CATEGORY_PRICE_PROJECTION_TABLE);
        }

        return self::$categoryPriceProjectionReady
            && $this->categoryIds()->count() > 0;
    }

    protected function categoryIds(): Collection
    {
        return $this->categories
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int)$id)
            ->unique()
            ->values();
    }
}
