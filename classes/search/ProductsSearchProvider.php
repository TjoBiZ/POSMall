<?php

namespace KodZero\POSMall\Classes\Search;

use Cms\Classes\Controller;
use October\Rain\Support\Collection;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;
use OFFLINE\SiteSearch\Classes\Providers\ResultsProvider;
use RainLab\Translate\Models\Attribute;

class ProductsSearchProvider extends ResultsProvider
{
    public function search()
    {
        $matchingProducts = $this->searchProducts();
        $matchingVariants = $this->searchVariants();

        $controller  = Controller::getController() ?? new Controller();
        $productPage = GeneralSettings::get('product_page');

        $groupByProducts = GeneralSettings::get('group_search_results_by_product', false);

        // Build a results collection, depending on the $groupByProducts setting.
        $results = new Collection();

        foreach ($matchingProducts->concat($matchingVariants) as $match) {
            // If results should not be grouped by product, just add this match to the collection.
            if (! $groupByProducts) {
                $results->push($match);

                continue;
            }

            // If matches should be grouped by product, and this match is a variant, check if
            // the related product is already in the results collection. If not, add it.
            if ($match instanceof Variant) {
                if (! $results->has($match->product_id)) {
                    $results->put($match->product_id, $match->product);
                }

                continue;
            }

            // The match is a Product, Add it to the result collection if it is not already there.
            if (! $results->has($match->id)) {
                $results->put($match->id, $match);
            }
        }
        
        // clean any null values from the results collection
        $results = $results->filter();
        
        // Build the OFFLINE.SiteSearch results collection.
        foreach ($results as $match) {
            $url = $controller->pageUrl($productPage, [
                'slug'    => $match->slug,
                'variant' => $match->variant_hash_id,
            ]);

            $result = $this->newResult();

            $result->relevance = 1;
            $result->title     = $match->name;
            $result->text      = $match->description ?: '';
            $result->url       = $url;
            $result->thumb     = $match->image;
            $result->model     = $match;
            $result->meta      = [
                'is_product' => true,
            ];

            $this->addResult($result);
        }

        return $this;
    }

    public function displayName()
    {
        return trans('kodzero.posmall::lang.common.product');
    }

    public function identifier()
    {
        return 'POSMall';
    }

    protected function searchProducts()
    {
        return $this->isDefaultLocale()
            ? $this->searchProductsFromDefaultLocale()
            : $this->searchProductsFromCurrentLocale();
    }

    protected function searchVariants()
    {
        return $this->isDefaultLocale()
            ? $this->searchVariantsFromDefaultLocale()
            : $this->searchVariantsFromCurrentLocale();
    }

    protected function searchProductsFromDefaultLocale()
    {
        return Product::where('inventory_management_method', 'single')
            ->published()
            ->withoutServiceCarriers()
            ->where($this->productQuery())
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    protected function searchVariantsFromDefaultLocale()
    {
        $pattern = $this->searchPattern();

        $variantQuery = function ($q) use ($pattern) {
            $this->whereSearchLike($q, 'name', $pattern)
                ->orWhereHas('product', $this->productQuery());
        };

        return Variant::where($variantQuery)
            ->published()
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    protected function productQuery()
    {
        $pattern = $this->searchPattern();

        return function ($q) use ($pattern) {
            $q->where('published', true)
                ->where(function ($q) use ($pattern) {
                    $this->whereSearchLike($q, 'name', $pattern)
                        ->orWhere('meta_title', 'ILIKE', $pattern)
                        ->orWhere('meta_description', 'ILIKE', $pattern)
                        ->orWhere('meta_keywords', 'ILIKE', $pattern)
                        ->orWhere('description', 'ILIKE', $pattern)
                        ->orWhere('description_short', 'ILIKE', $pattern)
                        ->orWhere('user_defined_id', 'ILIKE', $pattern)
                        ->orWhereHas('categories', function ($q) use ($pattern) {
                            $this->whereSearchLike($q, 'name', $pattern);
                        })
                        ->orWhereHas('brand', function ($q) use ($pattern) {
                            $this->whereSearchLike($q, 'name', $pattern);
                        });
                });
        };
    }

    /**
     * Returns all matching products with translated contents.
     *
     * @return Collection
     */
    protected function searchProductsFromCurrentLocale()
    {
        // First fetch all model ids with matching contents.
        $ids = $this->getModelIdsForQuery(Product::MORPH_KEY);

        // Then return all matching models via Eloquent.
        return Product::where('inventory_management_method', 'single')
            ->published()
            ->withoutServiceCarriers()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Returns all matching variants with translated contents.
     *
     * @return Collection
     */
    protected function searchVariantsFromCurrentLocale()
    {
        // First fetch all model ids with matching contents.
        $variantIds = $this->getModelIdsForQuery(Variant::MORPH_KEY);
        $productIds = $this->getModelIdsForQuery(Product::MORPH_KEY); // @TODO This query runs twice

        // Then return all matching models via Eloquent.
        return Variant::published()
            ->where(function ($q) use ($variantIds, $productIds) {
                $q->whereIn('id', $variantIds)
                    ->orWhereHas('product', function ($q) use ($productIds) {
                        $q->where('published', true)
                            ->whereIn('id', $productIds);
                    });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Returns the model IDs for the `modelClass` that match the search query
     *
     * @param string $modelClass
     *
     * @return \Illuminate\Support\Collection|Collection
     */
    protected function getModelIdsForQuery($modelClass)
    {
        $results = Attribute::where('model_type', $modelClass)
            ->where('locale', $this->currentLocale())
            ->where('attribute_data', 'ILIKE', $this->searchPattern())
            ->get(['model_id']);

        return collect($results)->pluck('model_id');
    }

    protected function searchPattern(): string
    {
        return '%' . $this->query . '%';
    }

    protected function whereSearchLike($query, string $column, string $pattern)
    {
        return $query->where($column, 'ILIKE', $pattern);
    }

    /**
     * Check if a translator is available and if the
     * current locale is the default locale.
     *
     * @return bool
     */
    protected function isDefaultLocale(): bool
    {
        $translator = $this->translator();

        if (! $translator) {
            return true;
        }

        return $translator->getLocale() === $translator->getDefaultLocale();
    }

    /**
     * Return the current locale
     *
     * @return string|null
     */
    protected function currentLocale(): ?string
    {
        $translator = $this->translator();

        if (! $translator) {
            return null;
        }

        return $translator->getLocale();
    }
}
