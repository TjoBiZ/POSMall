<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use DB;
use Cache;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use October\Rain\Support\Facades\Event;
use KodZero\POSMall\Classes\CategoryFilter\Filter;
use KodZero\POSMall\Classes\CategoryFilter\QueryString;
use KodZero\POSMall\Classes\CategoryFilter\RangeFilter;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Index\ProductEntry;
use KodZero\POSMall\Classes\Queries\PriceRangeQuery;
use KodZero\POSMall\Classes\Translations\TranslationPreloader;
use KodZero\POSMall\Classes\Utils\Money;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;
use Schema;

/**
 * The ProductsFilter component is used to filter items of
 * a specific category.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductsFilter extends POSMallComponent
{
    private const CATEGORY_PROJECTION_TABLE = 'kodzero_posmall_index_categories';
    private const CATEGORY_BRANDS_PROJECTION_TABLE = 'kodzero_posmall_index_category_brands';

    private static ?bool $categoryProjectionReady = null;

    /**
     * The active category.
     *
     * @var Category
     */
    public $category;

    /**
     * A Collection of all subcategories.
     *
     * @var Collection
     */
    public $categories;

    /**
     * Categories that can be used by the category filter.
     *
     * @var Collection<Category>
     */
    public $categoryFilterCategories;

    /**
     * All items in this category.
     *
     * @var Collection<Product|Variant>
     */
    public $items;

    /**
     * All available property values.
     *
     * @var Collection
     */
    public $values;

    /**
     * All available property filters.
     *
     * @var Collection
     */
    public $propertyGroups;

    /**
     * A collection of available Property models.
     *
     * @var Collection
     */
    public $props;

    /**
     * All active Filters.
     *
     * @var Collection
     */
    public $filter;

    /**
     * Query string representation of the active filter.
     *
     * @var string
     */
    public $queryString;

    /**
     * Show the price range filter.
     *
     * @var boolean
     */
    public $showPriceFilter;

    /**
     * Show the brand filter.
     *
     * @var boolean
     */
    public $showBrandFilter;

    /**
     * Show the categories filter.
     *
     * @var boolean
     */
    public $showCategoryFilter;

    /**
     * Show the on sale filter.
     *
     * @var boolean
     */
    public $showOnSaleFilter;

    /**
     * All available brands.
     *
     * @var Collection<Brand>
     */
    public $brands;

    /**
     * Include all items from child categories.
     *
     * @var boolean
     */
    public $includeChildren;

    /**
     * Also filter Variant properties.
     *
     * @var boolean
     */
    public $includeVariants;

    /**
     * The min and max values of the price range.
     *
     * @var array
     */
    public $priceRange;

    /**
     * The active Currency.
     *
     * @var Currency
     */
    public $currency;

    /**
     * The active sort order.
     *
     * @var string
     */
    public $sortOrder;

    /**
     * All available sort Options.
     *
     * @var array
     */
    public $sortOptions;

    /**
     * Cached filter HTML for public GET listing pages.
     *
     * @var string|null
     */
    public $filterMarkupPreRendered;

    /**
     * Sort order of the products component.
     *
     * @var string
     */
    public $productsComponentSort;

    /**
     * Category of the products component.
     *
     * @var Category
     */
    public $productsComponentCategory;

    /**
     * An instance of the money formatter class.
     *
     * @var Money
     */
    protected $money;

    /**
     * Filter state that should be used while rebuilding the component after AJAX.
     *
     * @var Collection|null
     */
    protected $activeFilterOverride;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'kodzero.posmall::lang.components.productsFilter.details.name',
            'description' => 'kodzero.posmall::lang.components.productsFilter.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        return [
            'category' => [
                'title' => 'kodzero.posmall::lang.common.category',
                'default' => null,
                'type' => 'dropdown',
            ],
            'includeChildren' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.includeChildren.title',
                'description' => 'kodzero.posmall::lang.components.productsFilter.properties.includeChildren.description',
                'default' => null,
                'type' => 'checkbox',
            ],
            'includeVariants' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.includeVariants.title',
                'description' => 'kodzero.posmall::lang.components.productsFilter.properties.includeVariants.description',
                'default' => null,
                'type' => 'checkbox',
            ],
            'showPriceFilter' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.showPriceFilter.title',
                'default' => '1',
                'type' => 'checkbox',
            ],
            'showBrandFilter' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.showBrandFilter.title',
                'default' => '1',
                'type' => 'checkbox',
            ],
            'showCategoryFilter' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.showCategoryFilter.title',
                'default' => '0',
                'type' => 'checkbox',
            ],
            'showOnSaleFilter' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.showOnSaleFilter.title',
                'default' => '0',
                'type' => 'checkbox',
            ],
            'includeSliderAssets' => [
                'title' => 'kodzero.posmall::lang.components.productsFilter.properties.includeSliderAssets.title',
                'description' => 'kodzero.posmall::lang.components.productsFilter.properties.includeSliderAssets.description',
                'default' => '1',
                'type' => 'checkbox',
            ],
        ];
    }

    /**
     * Options array for the category dropdown.
     *
     * @return array
     */
    public function getCategoryOptions()
    {
        return [':slug' => trans('kodzero.posmall::lang.components.products.properties.use_url')]
            + Category::get()->pluck('name', 'id')->toArray();
    }

    /**
     * The component is initialized.
     *
     * @return void
     */
    public function init()
    {
        if ((bool)$this->property('includeSliderAssets')) {
            $this->addJs('assets/js/nouislider.min.js');
            $this->addCss('assets/css/nouislider.min.css');
        }
        $this->money = app(Money::class);
    }

    /**
     * The component is executed.
     *
     * @return string|void
     */
    public function onRun()
    {
        $cacheKey = $this->requestFilterMarkupCacheKey();

        if ($cacheKey && Cache::has($cacheKey)) {
            $this->setVar('filterMarkupPreRendered', (string)Cache::get($cacheKey));
            return;
        }

        $this->setData();
    }

    /**
     * The filter values have been changed.
     *
     * @return array
     */
    public function onSetFilter()
    {
        $sortOrder = $this->getSortOrder();

        $data = collect(post('filter', []));

        if ($data->count() < 1) {
            return $this->replaceFilter(collect([]), $sortOrder);
        }

        $properties = Property::whereIn('slug', $data->keys())->get();
        $filter = $data->mapWithKeys(function ($values, $id) use ($properties) {
            $property = Filter::isSpecialProperty($id) ? $id : $properties->where('slug', $id)->first();

            if (is_array($values)
                && array_key_exists('min', $values)
                && array_key_exists('max', $values)
            ) {
                if ($values['min'] === '' && $values['max'] === '' || $values['min'] === null && $values['max'] === null) {
                    return [];
                }

                $filter = new RangeFilter(
                    $property,
                    [
                        $values['min'] ?? null,
                        $values['max'] ?? null,
                    ]
                );

                return $filter->isValid() ? [$id => $filter] : [];
            }

            // Remove empty set values
            $values = array_filter(array_wrap($values), 'strlen');

            return count($values) ? [$id => new SetFilter($property, $values)] : [];
        });

        return $this->replaceFilter($filter, $sortOrder);
    }

    /**
     * Get the min value of a Collection.
     *
     * @param $values
     *
     * @return mixed
     */
    public function getMinValue(Collection $values)
    {
        return $values->min('value');
    }

    /**
     * Get the max value of a Collection.
     *
     * @param $values
     *
     * @return mixed
     */
    public function getMaxValue(Collection $values)
    {
        return $values->max('value');
    }

    public function filterMarkup(): string
    {
        if ($this->filterMarkupPreRendered !== null) {
            return $this->filterMarkupPreRendered;
        }

        return Cache::remember(
            $this->filterMarkupCacheKey(),
            60,
            fn () => $this->renderPartial('@filters')
        );
    }

    /**
     * Return all categories for the categories filter.
     * @return Collection<Category>
     */
    public function getCategories()
    {
        if ($this->categoryFilterCategories) {
            return $this->categoryFilterCategories;
        }

        return $this->categoryFilterCategories = $this->getUsableCategoryFilterCategories();
    }

    /**
     * This method sets all variables needed for this component to work.
     *
     * @return void
     */
    protected function setData()
    {
        $this->setVar('currency', Currency::activeCurrency());
        $this->setVar('showPriceFilter', (bool)$this->property('showPriceFilter'));
        $this->setVar('showBrandFilter', (bool)$this->property('showBrandFilter'));
        $this->setVar('showOnSaleFilter', (bool)$this->property('showOnSaleFilter'));
        $wantsCategoryFilter = (bool)$this->property('showCategoryFilter');
        $this->categoryFilterCategories = $wantsCategoryFilter
            ? $this->getCategories()
            : new EloquentCollection();

        $this->setVar('categoryFilterCategories', $this->categoryFilterCategories);
        $this->setVar(
            'showCategoryFilter',
            $wantsCategoryFilter && $this->categoryFilterCategories->count() > 1
        );

        // The includeChildren and includeVariants properties are set by the
        // products component. If the user specifies explicit values via the
        // component props we can use these instead.
        $includeChildren = $this->property('includeChildren');

        if ($includeChildren !== null) {
            $this->setVar('includeChildren', (bool)$includeChildren);
        }
        $includeVariants = $this->property('includeVariants');

        if ($includeVariants !== null) {
            $this->setVar('includeVariants', (bool)$includeVariants);
        }

        $this->setVar('category', $this->getCategory());
        $this->setVar('filter', $this->getFilter());

        $this->setVar('categories', $this->getActiveCategories($this->filter));

        if ($this->showPriceFilter) {
            $this->setPriceRange();
        }

        if ($this->showBrandFilter) {
            $this->setBrands();
        }

        $this->setVar('propertyGroups', $this->getPropertyGroups());
        $this->setProps();

        $nullOption = [null => trans('kodzero.posmall::frontend.select')];

        $this->setVar('sortOrder', $this->getSortOrder());
        $this->setVar('sortOptions', array_merge($nullOption, SortOrder::options(true)));
    }

    /**
     * Set the available price range.
     *
     * This gets the lowest and higest prices from all items
     * of this category.
     *
     * @return void
     */
    protected function setPriceRange()
    {
        $defaultCurrency = Currency::defaultCurrency();
        $range = Cache::remember(
            $this->filterCacheKey('price_range', [$defaultCurrency->id]),
            60,
            fn () => (new PriceRangeQuery($this->categories, $defaultCurrency))->query()->first()
        );

        // If the active currency is not the default currency we might have to
        // extend the range by dynamically calculated prices.
        if ($this->currency->id !== $defaultCurrency->id) {
            $calculatedMin = $range->min * $this->currency->rate;
            $calculatedMax = $range->max * $this->currency->rate;

            $currencyRange = Cache::remember(
                $this->filterCacheKey('price_range', [$this->currency->id]),
                60,
                fn () => (new PriceRangeQuery($this->categories, $this->currency))->query()->first()
            );

            $range->min = $this->higher($currencyRange->min, $calculatedMin);
            $range->max = $this->lower($currencyRange->max, $calculatedMax);
        }

        $min = $this->money->round($range->min, $this->currency->decimals);
        $max = $this->money->round($range->max, $this->currency->decimals);

        $this->setVar('priceRange', $min === $max ? false : [$min, $max]);
    }

    /**
     * Fetch all brands that are present in the current category.
     *
     * @return void
     */
    protected function setBrands()
    {
        $brands = Cache::remember($this->filterCacheKey('brands'), 60, function (): array {
            if ($this->canUseCategoryProjection()) {
                return DB::table(self::CATEGORY_BRANDS_PROJECTION_TABLE . ' as cb')
                    ->join('kodzero_posmall_brands as b', 'cb.brand', '=', 'b.slug')
                    ->where('cb.index_name', ProductEntry::INDEX)
                    ->whereIn('cb.category_id', $this->categories->pluck('id'))
                    ->select('b.*')
                    ->distinct()
                    ->orderBy('b.name')
                    ->get()
                    ->toArray();
            }

            return DB::table('kodzero_posmall_products')
                ->where('kodzero_posmall_products.published', '=', true)
                ->when($this->categories->count() > 0, function ($query) {
                    $query->whereIn('kodzero_posmall_category_product.category_id', $this->categories->pluck('id'));
                })
                ->select('kodzero_posmall_brands.*')
                ->distinct()
                ->join('kodzero_posmall_brands', 'kodzero_posmall_products.brand_id', '=', 'kodzero_posmall_brands.id')
                ->join(
                    'kodzero_posmall_category_product',
                    'kodzero_posmall_products.id',
                    '=',
                    'kodzero_posmall_category_product.product_id'
                )
                ->orderBy('kodzero_posmall_brands.name')
                ->get()
                ->toArray();
        });

        $this->setVar('brands', Brand::hydrate($brands));
    }

    protected function canUseCategoryProjection(): bool
    {
        if (self::$categoryProjectionReady === null) {
            self::$categoryProjectionReady = DB::connection()->getDriverName() === 'pgsql'
                && Schema::hasTable(self::CATEGORY_PROJECTION_TABLE)
                && Schema::hasTable(self::CATEGORY_BRANDS_PROJECTION_TABLE);
        }

        return self::$categoryProjectionReady
            && $this->categories->count() > 0;
    }

    protected function filterCacheKey(string $name, array $extra = []): string
    {
        $categoryIds = $this->categories
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int)$id)
            ->sort()
            ->values()
            ->all();

        return 'kodzero.posmall.products_filter.' . $name . '.' . md5(json_encode([$categoryIds, $extra]));
    }

    /**
     * Get all PropertyGroups in this Category.
     *
     * @return mixed
     */
    protected function getPropertyGroups()
    {
        if (!$this->category->exists && $this->categories->count() < 1) {
            $groups = PropertyGroup::with('filterable_properties')->get();
            TranslationPreloader::preloadNested($groups, ['filterable_properties']);

            return $groups;
        }

        $groups = new EloquentCollection($this->categories
            ->flatMap(function (Category $category) {
                return $category
                    ->load('property_groups')
                    ->inherited_property_groups;
            })
            ->unique('id')
            ->values()
            ->all());

        $groups->load('filterable_properties');
        TranslationPreloader::preloadNested($groups, ['filterable_properties']);

        return $groups->reject(fn(PropertyGroup $group) => $group->filterable_properties->count() < 1)->sortBy('pivot.relation_sort_order');
    }

    protected function getUsableCategoryFilterCategories(): EloquentCollection
    {
        $categories = Category::whereHas('publishedProducts', function ($query) {
                $query->withoutServiceCarriers();
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $categories->each(function (Category $category) {
            $category->setRelation('children', new EloquentCollection());
        });
    }

    /**
     * Pull all the properties from all property groups. These are needed
     * to generate possible filter values.
     *
     * @return void
     */
    protected function setProps()
    {
        $this->values = Property::getValuesForCategory($this->categories);

        Event::fire('posmall.productsFilter.props.extend', [$this]);

        $valueKeys = $this->values->keys();
        $props = $this->propertyGroups->flatMap->filterable_properties->unique();

        // Remove any property that has no available filters.
        $this->props = $props->filter(fn (Property $property) => $valueKeys->contains($property->id));

        $groupKeys = $this->props->pluck('pivot.property_group_id');

        // Remove any property group that has no available properties.
        $this->propertyGroups = $this->propertyGroups->filter(fn (PropertyGroup $group) => $groupKeys->contains($group->id));
    }

    /**
     * Get the currently active category.
     *
     * @return mixed
     */
    protected function getCategory()
    {
        // Use the category from the products component if nothing else is specified.
        if ($this->productsComponentCategory && $this->property('category') === null) {
            return $this->productsComponentCategory;
        }

        // If no category is set, use the root category.
        if ($this->property('category') === null) {
            return new Category();
        }

        return Category::bySlugOrId($this->param('slug'), $this->property('category'));
    }

    /**
     * Get the currently active Filter from the QueryString.
     *
     * @return Collection
     */
    protected function getFilter()
    {
        if ($this->activeFilterOverride instanceof Collection) {
            return $this->activeFilterOverride;
        }

        $filter = array_wrap(request()->all() ?? []);

        return (new QueryString())->deserialize($filter, $this->category);
    }

    protected function getActiveCategories(Collection $filter): EloquentCollection
    {
        $selectedCategoryIds = $this->selectedCategoryIdsFromFilter($filter);

        if ($selectedCategoryIds->count() > 0) {
            return Category::whereIn('id', $selectedCategoryIds)->get();
        }

        $categories = new EloquentCollection([]);

        if ($this->category->exists) {
            $categories->push($this->category);
        }

        if ($this->includeChildren && $this->category->exists) {
            $categories = $this->category->getAllChildrenAndSelf();
        }

        return $categories;
    }

    protected function selectedCategoryIdsFromFilter(Collection $filter): Collection
    {
        if (!$filter->has('category_id')) {
            return collect([]);
        }

        return collect($filter->get('category_id')->values())
            ->filter(fn($id) => is_numeric($id))
            ->map(fn($id) => (int)$id)
            ->unique()
            ->values();
    }

    protected function removeUnavailablePropertyFilters(Collection $filter): Collection
    {
        $availableSlugs = $this->props->pluck('slug');

        return $filter->filter(function (Filter $activeFilter, $slug) use ($availableSlugs) {
            if (Filter::isSpecialProperty((string)$slug)) {
                return true;
            }

            return $activeFilter->property instanceof Property && $availableSlugs->contains($activeFilter->property->slug);
        });
    }

    /**
     * Get the currently active SortOrder.
     *
     * @return string
     */
    protected function getSortOrder(): string
    {
        $fallback = optional($this->productsComponentSort)->key() ?? SortOrder::default();

        return post('sort', get('sort', $fallback));
    }

    /**
     * Replace the currently active filter query string.
     *
     * @param Collection $filter
     * @param $sortOrder
     *
     * @return array
     */
    protected function replaceFilter(Collection $filter, $sortOrder)
    {
        $this->activeFilterOverride = $filter;
        $this->setData();
        $filter = $this->removeUnavailablePropertyFilters($filter);
        $this->activeFilterOverride = $filter;
        $this->setVar('filter', $filter);
        $this->setVar('sortOrder', $sortOrder);

        return [
            'filter' => $filter,
            'sort' => $sortOrder,
            'filterMarkup' => $this->filterMarkup(),
            'queryString' => (new QueryString())->serialize($filter, $sortOrder),
        ];
    }

    protected function filterMarkupCacheKey(): string
    {
        $requestCacheKey = $this->requestFilterMarkupCacheKey();

        if ($requestCacheKey) {
            return $requestCacheKey;
        }

        $filterString = $this->filter instanceof Collection
            ? (new QueryString())->serialize($this->filter, (string)$this->sortOrder)
            : '';

        return 'kodzero.posmall.products_filter.markup.' . md5(json_encode([
            app()->getLocale(),
            optional($this->currency)->id,
            optional($this->currency)->code,
            (bool)$this->showPriceFilter,
            (bool)$this->showBrandFilter,
            (bool)$this->showCategoryFilter,
            (bool)$this->showOnSaleFilter,
            $this->categories ? $this->categories->pluck('id')->map(fn ($id) => (int)$id)->sort()->values()->all() : [],
            $this->categoryFilterCategories ? $this->categoryFilterCategories->pluck('id')->map(fn ($id) => (int)$id)->sort()->values()->all() : [],
            $this->brands ? $this->brands->pluck('slug')->sort()->values()->all() : [],
            $this->priceRange,
            $this->propertyGroups ? $this->propertyGroups->pluck('id')->map(fn ($id) => (int)$id)->sort()->values()->all() : [],
            $this->props ? $this->props->pluck('id')->map(fn ($id) => (int)$id)->sort()->values()->all() : [],
            $filterString,
            (string)$this->sortOrder,
        ]));
    }

    protected function requestFilterMarkupCacheKey(): ?string
    {
        if (!request()->isMethod('GET')) {
            return null;
        }

        $query = request()->query();
        ksort($query);

        return 'kodzero.posmall.products_filter.markup.request.' . md5(json_encode([
            'locale' => app()->getLocale(),
            'currency' => optional(Currency::activeCurrency())->code,
            'category' => optional($this->productsComponentCategory)->id ?: $this->property('category'),
            'slug' => $this->param('slug'),
            'includeChildren' => $this->includeChildren ?? $this->property('includeChildren'),
            'includeVariants' => $this->includeVariants ?? $this->property('includeVariants'),
            'showPriceFilter' => (bool)$this->property('showPriceFilter'),
            'showBrandFilter' => (bool)$this->property('showBrandFilter'),
            'showCategoryFilter' => (bool)$this->property('showCategoryFilter'),
            'showOnSaleFilter' => (bool)$this->property('showOnSaleFilter'),
            'sort' => optional($this->productsComponentSort)->key() ?: $this->property('sort'),
            'query' => $query,
        ]));
    }

    /**
     * Return the higher of two values.
     *
     * @param $a
     * @param $b
     *
     * @return mixed
     */
    protected function higher($a, $b)
    {
        return $a > $b ? $b : $a;
    }

    /**
     * Return the lower of two values.
     *
     * @param $a
     * @param $b
     *
     * @return mixed
     */
    protected function lower($a, $b)
    {
        return $a > $b ? $a : $b;
    }
}
