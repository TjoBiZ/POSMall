<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use ArrayAccess;
use Cache;
use Flash;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use October\Rain\Exception\ValidationException;
use October\Rain\Support\Facades\Event;
use KodZero\POSMall\Classes\CategoryFilter\Filter;
use KodZero\POSMall\Classes\CategoryFilter\QueryString;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;
use KodZero\POSMall\Classes\CategoryFilter\SortOrder\SortOrder;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Traits\CustomFields;
use KodZero\POSMall\Classes\Translations\TranslationPreloader;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Models\Cart as CartModel;
use KodZero\POSMall\Models\Category as CategoryModel;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\Variant;
use Redirect;
use Validator;

/**
 * The Products components displays a list of Products.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Products extends POSMallComponent
{
    use CustomFields;

    /**
     * The category to select Products from.
     *
     * @var CategoryModel
     */
    public $category;

    /**
     * Include this category's child categories as well.
     * @var bool
     */
    public $includeChildren;

    /**
     * Display Variants, not Products
     * @var bool
     */
    public $includeVariants;

    /**
     * All items to display.
     *
     * @var Product[]|Variant[]
     */
    public $items;

    /**
     * How many items to show per page.
     *
     * @var integer
     */
    public $perPage;

    /**
     * The current page number.
     *
     * @var integer
     */
    public $pageNumber;

    /**
     * The total item count of all pages.
     *
     * @var integer
     */
    public $itemCount;

    /**
     * The name of the product detail page.
     *
     * @var string
     */
    public $productPage;

    /**
     * Show more than one page.
     *
     * @var bool
     */
    public $paginate;

    /**
     * Sort order of the items.
     *
     * @var string
     */
    public $sort;

    /**
     * ProductsFilter Component.
     *
     * @var ProductsFilter
     */
    public $filterComponent;

    /**
     * Set the category's name as page title.
     *
     * @var bool
     */
    public $setPageTitle;

    /**
     * Google Tag Manager dataLayer code.
     *
     * @var string
     */
    public $dataLayer;

    /**
     * Cached public listing markup for guest category pages.
     *
     * @var string|null
     */
    public $entriesMarkup;

    /**
     * Contains the current category and all child categories.
     *
     * @var Collection
     */
    protected $categories;

    /**
     * Forced filter string
     *
     * @var string
     */
    protected $filter;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.products.details.name',
            'description' => 'kodzero.posmall::lang.components.products.details.description',
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
            'category'        => [
                'title'   => 'kodzero.posmall::lang.common.category',
                'default' => null,
                'type'    => 'dropdown',
            ],
            'filterComponent' => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.filter_component.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.filter_component.description',
                'default'     => 'productsFilter',
                'type'        => 'string',
            ],
            'filter'          => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.filter.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.filter.description',
                'default'     => null,
                'type'        => 'string',
            ],
            'setPageTitle'    => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.set_page_title.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.set_page_title.description',
                'default'     => '0',
                'type'        => 'checkbox',
            ],
            'includeVariants' => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.include_variants.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.include_variants.description',
                'default'     => '1',
                'type'        => 'checkbox',
            ],
            'includeChildren' => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.include_children.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.include_children.description',
                'default'     => '0',
                'type'        => 'checkbox',
            ],
            'perPage'         => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.per_page.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.per_page.description',
                'default'     => '9',
                'type'        => 'string',
            ],
            'paginate'        => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.paginate.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.paginate.description',
                'default'     => '1',
                'type'        => 'checkbox',
            ],
            'sort'            => [
                'title'       => 'kodzero.posmall::lang.components.products.properties.sort.title',
                'description' => 'kodzero.posmall::lang.components.products.properties.sort.description',
                'default'     => null,
                'type'        => 'dropdown',
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
        return [
            null    => trans('kodzero.posmall::lang.components.products.properties.no_category_filter'),
            ':slug' => trans('kodzero.posmall::lang.components.products.properties.use_url'),
        ]
            + CategoryModel::get()->pluck('name', 'id')->toArray();
    }

    /**
     * Options array for the sort order dropdown.
     *
     * @return array
     */
    public function getSortOptions()
    {
        return [null => trans('kodzero.posmall::lang.common.none')] + SortOrder::dropdownOptions();
    }

    /**
     * The component is executed.
     *
     * @return string|void
     */
    public function onRun()
    {
        try {
            $this->setData();
        } catch (ModelNotFoundException $e) {
            return $this->controller->run('404');
        }

        // If a category is selected and the page title should be set, do so.
        if ($this->category && $this->setPageTitle) {
            $this->page->title            = $this->category->meta_title ?: $this->category->name;
            $description = $this->category->meta_description
                ?: Str::limit($this->cleanMetaText($this->category->description_short ?: $this->category->description), 160);
            $this->page->meta_description = $this->cleanMetaText($description);
        }

        if (!($this->page['posmallJsonLd'] ?? null)) {
            $this->page['posmallJsonLd'] = $this->listingJsonLd();
        }
    }

    /**
     * Add a product to the cart.
     *
     * @throws ValidationException
     * @return mixed
     */
    public function onAddToCart()
    {
        $validation = Validator::make(post() ?: [], [
            'product' => 'required',
            'quantity' => 'bail|nullable|integer|min:1',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $productId = $this->decode(post('product'));
        $variantId = $this->decode(post('variant'));
        $values    = $this->validateCustomFields(post('fields', []));

        $product = Product::published()->where('id', $productId)->firstOrFail();
        $variant = null;

        if ($variantId) {
            $variant = Variant::published()
                ->where('product_id', $product->id)
                ->where('id', $variantId)
                ->firstOrFail();
        }

        $cart     = CartModel::byUser(Auth::user());
        $quantity = (int)post('quantity', $product->quantity_default ?? 1);

        if ($quantity < 1) {
            throw new ValidationException(['quantity' => trans('kodzero.posmall::lang.common.invalid_quantity')]);
        }

        try {
            $cart->addProduct($product, $quantity, $variant, $values);
        } catch (OutOfStockException $e) {
            throw new ValidationException(['stock' => trans('kodzero.posmall::lang.common.stock_limit_reached')]);
        }

        // If the redirect_to_cart option is set to true the user is redirected to the cart.
        if ((bool)GeneralSettings::get('redirect_to_cart', false) === true) {
            $cartPage = GeneralSettings::get('cart_page');

            return Redirect::to($this->controller->pageUrl($cartPage));
        }

        Flash::success(trans('kodzero.posmall::frontend.cart.added'));

        return [
            'added'    => true,
            'item'     => $this->dataLayerArray($product, $variant),
            'currency' => optional(Currency::activeCurrency())->only('symbol', 'code', 'rate', 'decimals'),
            'new_items_count' => optional($cart->products)->count() ?? 0,
            'new_items_quantity' => optional($cart->products)->sum('quantity') ?? 0,
            'quantity' => $quantity,
        ];
    }

    /**
     * This method sets all variables needed for this component to work.
     *
     * @return void
     */
    protected function setData()
    {
        $this->setVar('includeChildren', (bool)$this->property('includeChildren'));
        $this->setVar('includeVariants', (bool)$this->property('includeVariants'));
        $this->setVar('filter', $this->property('filter'));
        $this->setVar('category', $this->getCategory());

        $filterComponent = $this->controller->findComponentByName($this->property('filterComponent'));

        if ($filterComponent) {
            $filterComponent->productsComponentSort     = $this->getSortOrder();
            $filterComponent->productsComponentCategory = $this->category;
            $filterComponent->includeChildren           = $this->includeChildren;
            $filterComponent->includeVariants           = $this->includeVariants;
            $this->filterComponent                      = $filterComponent;
        }

        $this->setVar('sort', $this->property('sort'));
        $this->setVar('setPageTitle', (bool)$this->property('setPageTitle'));
        $this->setVar('paginate', (bool)$this->property('paginate'));

        $this->setVar('productPage', GeneralSettings::get('product_page'));
        $this->setVar('pageNumber', (int)request('page', 1));
        $this->setVar('perPage', (int)$this->property('perPage'));

        $entriesCacheKey = $this->listingEntriesCacheKey();

        if ($entriesCacheKey) {
            $cachedEntries = Cache::get($entriesCacheKey);

            if (is_array($cachedEntries) && isset($cachedEntries['markup'])) {
                $this->setVar('items', new LengthAwarePaginator(collect([]), 0, $this->perPage, $this->pageNumber));
                $this->setVar('dataLayer', null);
                $this->setVar('entriesMarkup', (string)$cachedEntries['markup']);
                $this->page['posmallJsonLd'] = $cachedEntries['jsonLd'] ?? null;
                return;
            }
        }

        if ($this->category) {
            $categories = collect([$this->category]);

            if ($this->includeChildren) {
                $categories = $this->category->getAllChildrenAndSelf();
            }

            $this->setVar('categories', $categories);
        }

        $this->setVar('items', $this->getItems());

        $this->setVar('dataLayer', $this->handleDataLayer());

        if ($entriesCacheKey) {
            $this->page['posmallJsonLd'] = $this->listingJsonLd();
            $this->setVar('entriesMarkup', $this->renderListingEntriesMarkup());
            Cache::put($entriesCacheKey, [
                'markup' => $this->entriesMarkup,
                'jsonLd' => $this->page['posmallJsonLd'],
            ], 60);
        }
    }

    protected function renderListingEntriesMarkup(): string
    {
        if ($this->items && $this->items->count()) {
            return $this->renderPartial('@items', ['items' => $this->items]);
        }

        return $this->renderPartial('@empty');
    }

    protected function listingEntriesCacheKey(): ?string
    {
        if (!request()->isMethod('GET')) {
            return null;
        }

        if ($this->requestHasUserSessionCookie() && Auth::user()) {
            return null;
        }

        if ($this->page->layout->hasComponent('enhancedEcommerceAnalytics')) {
            return null;
        }

        $query = request()->query();
        ksort($query);

        return 'kodzero.posmall.products.entries.' . md5(json_encode([
                'version' => 3,
            'locale' => app()->getLocale(),
            'currency' => optional(Currency::activeCurrency())->code,
            'category' => optional($this->category)->id,
            'includeChildren' => $this->includeChildren,
            'includeVariants' => $this->includeVariants,
            'filter' => $this->filter,
            'sort' => $this->sort,
            'pageNumber' => $this->pageNumber,
            'perPage' => $this->perPage,
            'productPage' => $this->productPage,
            'query' => $query,
        ]));
    }

    protected function requestHasUserSessionCookie(): bool
    {
        $sessionCookie = (string)config('session.cookie', 'october_session');

        return $sessionCookie !== '' && request()->cookies->has($sessionCookie);
    }

    /**
     * Retrieve all items for the current page from the index.
     *
     * @return ArrayAccess
     */
    protected function getItems(): ArrayAccess
    {
        $filters   = $this->getFilters();
        $sortOrder = $this->getSortOrder();

        $model    = $this->includeVariants ? new Variant() : new Product();
        $useIndex = $this->includeVariants ? 'variants' : 'products';

        $sortOrder->setFilters(clone $filters);

        /** @var Index $index */
        $index  = app(Index::class);
        $result = $index->fetch($useIndex, $filters, $sortOrder, $this->perPage, $this->pageNumber);

        // Every id that is not an int is a "ghosted" variant, with an id like
        // product-1. These ids have to be fetched separately. This enables us to
        // query variants and products that don't have any variants from the same index.
        $itemIds  = array_map('intval', array_filter($result->ids, 'is_numeric'));
        $ghostIds = array_diff($result->ids, $itemIds);

        $models = $model->with($this->productIncludes())->find($itemIds);
        TranslationPreloader::preload($models);
        $ghosts = $this->getGhosts($ghostIds);

        // Preload all pricing information for related products. This is used in case a Variant
        // is inheriting it's parent product's pricing information.
        if ($model instanceof Variant) {
            $models->load([
                'product.customer_group_prices.currency',
                'product.prices.currency',
                'product.additional_prices.currency',
            ]);
        }

        // Insert the Ghost models back at their old position so the sort order remains.
        $resultSet = $this->mapIndexResultToModels($result->ids, $models, $ghosts);

        if (! $this->includeVariants
            && $this->paginate
            && $resultSet->count() < count($result->ids)
        ) {
            $result = $index->fetch($useIndex, $filters, $sortOrder, $this->perPage * $this->pageNumber + 50, 1);
            $itemIds  = array_map('intval', array_filter($result->ids, 'is_numeric'));
            $ghostIds = array_diff($result->ids, $itemIds);
            $models = $model->with($this->productIncludes())->find($itemIds);
            TranslationPreloader::preload($models);
            $ghosts = $this->getGhosts($ghostIds);
            $resultSet = $this->mapIndexResultToModels($result->ids, $models, $ghosts)
                ->forPage($this->pageNumber, $this->perPage)
                ->values();
        }

        return $this->paginate(
            $resultSet,
            $result->totalCount
        );
    }

    /**
     * Fetch all ghost products.
     *
     * Products that don't have any Variants are still stored in the
     * Variants index to make it easier to query everything at once.
     * This method removes the product-X prefix from the ID and fetches
     * the effective Product models to display.
     *
     * @param array $ids
     *
     * @return Collection
     */
    protected function getGhosts(array $ids)
    {
        if (count($ids) < 1) {
            return collect([]);
        }

        $ids = array_map(fn ($id) => (int)str_replace('product-', '', $id), $ids);

        $products = Product::with($this->productIncludes())->find($ids);
        TranslationPreloader::preload($products);

        return $products;
    }

    protected function mapIndexResultToModels(array $ids, Collection $models, Collection $ghosts): Collection
    {
        return collect($ids)
            ->map(fn ($id) => is_numeric($id)
                ? $models->find((int)$id)
                : $ghosts->find(str_replace('product-', '', $id)))
            ->reject(fn ($item) => ! $item || $this->isServiceCarrierProduct($item))
            ->values();
    }

    protected function isServiceCarrierProduct($item): bool
    {
        if (! $item) {
            return false;
        }

        $sku = $item instanceof Variant
            ? optional($item->product)->user_defined_id
            : $item->user_defined_id;

        return is_string($sku) && str_starts_with($sku, 'POSMALL-SERVICE-CARRIER-');
    }

    /**
     * Paginate the result set.
     *
     * @param Collection $items
     * @param int $totalCount
     *
     * @return LengthAwarePaginator
     */
    protected function paginate(Collection $items, int $totalCount)
    {
        $paginator = new LengthAwarePaginator(
            $items,
            $totalCount,
            $this->perPage,
            $this->pageNumber
        );

        $paginator->appends(request()->all());

        $pageUrl = $this->controller->pageUrl(
            $this->page->fileName,
            ['slug' => $this->param('slug')]
        );

        return $paginator->setPath($pageUrl);
    }

    /**
     * Retrieve the Category by ID or from the page's :slug parameter.
     *
     * @return CategoryModel|null
     */
    protected function getCategory()
    {
        if ($this->category) {
            return $this->category;
        }

        if ($this->property('category') === null) {
            return null;
        }

        if ($this->property('category') === ':slug' && $this->param('slug') === null) {
            throw new LogicException(
                'POSMall: A :slug URL parameter is needed when selecting products by category slug.'
            );
        }

        return CategoryModel::bySlugOrId($this->param('slug'), $this->property('category'));
    }

    /**
     * Deserialize the URL parameter into Filter classes.
     *
     * @return Collection
     */
    protected function getFilters(): Collection
    {
        $filter = request()->all();

        if ($this->filter) {
            parse_str($this->filter, $filter);
        }

        $filter = array_wrap($filter);

        $filters = (new QueryString())->deserialize($filter, $this->category);

        if ($this->categories && !isset($filters['category_id'])) {
            $filters->put('category_id', new SetFilter('category_id', $this->categories->pluck('id')->toArray()));
        }

        $this->excludeServiceCarrierProducts($filters);

        $filters = $this->removeUnavailablePropertyFilters($filters);

        Event::fire('posmall.products.filter.extend', [$this, $filters]);

        return $filters;
    }

    protected function excludeServiceCarrierProducts(Collection $filters): void
    {
        if ($filters->has('product_id')) {
            return;
        }

        $ids = Cache::remember('kodzero.posmall.service_carrier_product_ids', 60, function (): array {
            return Product::published()
                ->serviceCarriers()
                ->pluck('id')
                ->map(fn ($id) => (int)$id)
                ->all();
        });

        if (count($ids) < 1) {
            return;
        }

        $filters->put('product_id', new SetFilter('product_id', $ids, true));
    }

    protected function removeUnavailablePropertyFilters(Collection $filters): Collection
    {
        $categories = $this->categoriesForFilterScope($filters);

        if ($categories->count() < 1) {
            return $filters;
        }

        $availablePropertyIds = Property::getValuesForCategory($categories)
            ->keys()
            ->map(fn($id) => (int)$id);

        return $filters->filter(function ($filter) use ($availablePropertyIds) {
            if (!$filter instanceof Filter || !$filter->property instanceof Property) {
                return true;
            }

            return $availablePropertyIds->contains((int)$filter->property->id);
        });
    }

    protected function categoriesForFilterScope(Collection $filters): EloquentCollection
    {
        if ($filters->has('category_id')) {
            $ids = collect($filters->get('category_id')->values())
                ->filter(fn($id) => is_numeric($id))
                ->map(fn($id) => (int)$id)
                ->unique()
                ->values();

            return $ids->count() > 0
                ? CategoryModel::whereIn('id', $ids)->get()
                : new EloquentCollection();
        }

        if ($this->categories) {
            $ids = collect($this->categories)->pluck('id')->filter()->unique()->values();

            return $ids->count() > 0
                ? CategoryModel::whereIn('id', $ids)->get()
                : new EloquentCollection();
        }

        return new EloquentCollection();
    }

    /**
     * Get the sort order selected by the shop admin or the user.
     * Use fallback if neither is present.
     *
     * @return SortOrder
     */
    protected function getSortOrder(): SortOrder
    {
        $key = input('sort', $this->property('sort') ?? SortOrder::default());

        return SortOrder::fromKey($key);
    }

    /**
     * Return an array of default Product includes.
     *
     * @return array
     */
    protected function productIncludes(): array
    {
        return [
            'image_sets.images',
            'variants',
            'prices.currency',
            'customer_group_prices',
            'additional_prices.currency',
        ];
    }

    /**
     * Generate Google Tag Manager dataLayer code.
     */
    private function handleDataLayer()
    {
        if (!$this->page->layout->hasComponent('enhancedEcommerceAnalytics')) {
            return;
        }

        /** @var LengthAwarePaginator */
        $items = $this->items;

        $dataLayer = [
            'ecommerce' => [
                'currencyCode' => Currency::activeCurrency()->code,
                'impressions'  => $items->map(function ($item, $index) {
                    $name    = $item instanceof Product ? $item->product : $item->product->name;
                    $variant = $item instanceof Product ? null : $item->name;

                    $category = optional($this->category)->name;
                    $list     = $category ? 'Category ' . $category : '';

                    return [
                        'id'       => $item->prefixedId,
                        'name'     => $name,
                        'price'    => $item->price()->decimal,
                        'brand'    => optional($item->brand)->name,
                        'category' => $category,
                        'variant'  => $variant,
                        'list'     => $list,
                        'position' => $index * $this->pageNumber,
                    ];
                }),
            ],
        ];

        return json_encode($dataLayer);
    }

    /**
     * Return the dataLayer representation of an item.
     *
     * @param null $product
     * @param null $variant
     *
     * @return array
     */
    private function dataLayerArray($product = null, $variant = null)
    {
        $product ??= $this->product;
        $variant ??= $this->variant;

        $item = $variant ?? $product;

        return [
            'id'       => $item->prefixedId,
            'name'     => $product->name,
            'price'    => $item->price()->decimal,
            'brand'    => optional($item->brand)->name,
            'category' => optional(optional($item->categories)->first())->name,
            'variant'  => optional($variant)->name,
        ];
    }

    private function cleanMetaText($value): string
    {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function listingJsonLd(): ?string
    {
        if (! $this->items || ! $this->productPage) {
            return null;
        }

        $graphs = [];
        $items = $this->items instanceof LengthAwarePaginator
            ? $this->items->getCollection()
            : collect($this->items);

        $listItems = $items->filter(fn ($item) => is_object($item))->values()->map(function ($item, int $index) {
            $product = $item instanceof Variant ? $item->product : $item;
            $variant = $item instanceof Variant ? $item->hashId : ($item->variantHashId ?? null);

            if (! $product || ! $product->slug || ! $item->name) {
                return null;
            }

            return [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => $this->controller->pageUrl($this->productPage, [
                    'slug' => $product->slug,
                    'variant' => $variant,
                ]),
                'name' => $item->name,
            ];
        })->filter()->values();

        if ($listItems->count() > 0) {
            $graphs[] = [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'itemListElement' => $listItems->all(),
            ];
        }

        if ($this->category) {
            $graphs[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => 'Catalog',
                        'item' => $this->controller->pageUrl('posmall-catalog'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => $this->category->name,
                        'item' => request()->url(),
                    ],
                ],
            ];
        }

        if (count($graphs) < 1) {
            return null;
        }

        $data = count($graphs) === 1 ? $graphs[0] : ['@context' => 'https://schema.org', '@graph' => $graphs];

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
