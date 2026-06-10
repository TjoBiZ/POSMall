<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Flash;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redirect;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Classes\Queries\VariantByPropertyValuesQuery;
use KodZero\POSMall\Classes\Traits\CustomFields;
use KodZero\POSMall\Classes\Translations\TranslationPreloader;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\CustomFieldValue;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Price;
use KodZero\POSMall\Models\Product as ProductModel;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyValue;
use KodZero\POSMall\Models\ReviewSettings;
use KodZero\POSMall\Models\Variant;
use Request;
use Session;
use System\Classes\PluginManager;
use Validator;

/**
 * The Product component displays all information of a single Product.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Product extends POSMallComponent
{
    use CustomFields;

    /**
     * The item to display.
     *
     * @var Product|Variant;
     */
    public $item;

    /**
     * The Product model belonging to the item.
     *
     * @var ProductModel;
     */
    public $product;

    /**
     * The Variants belonging to the ProductModel.
     *
     * @var Collection
     */
    public $variants;

    /**
     * All available PropertyValues of the Variant.
     *
     * @var Collection
     */
    public $variantPropertyValues;

    /**
     * Available Property models.
     *
     * Named "props" to prevent naming conflict with base class.
     *
     * @var Collection
     */
    public $props;

    /**
     * The Variant to display.
     *
     * @var Variant
     */
    public $variant;

    /**
     * Product images plus selected Variant images for the current detail view.
     *
     * @var Collection
     */
    public $galleryImages;

    /**
     * Google Tag Manager dataLayer code.
     *
     * @var string
     */
    public $dataLayer;

    /**
     * Redirect to the new Product/Variant detail page when properties
     * are changed instead of only reloading the add to cart partial.
     *
     * @var boolean
     */
    public $redirectOnPropertyChange;

    /**
     * Show or hide reviews, defined in ReviewSettings.
     * @var bool
     */
    public $showReviews;

    /**
     * The ID of the Variant to display.
     * @var integer
     */
    protected $variantId;

    /**
     * Indicate's that the requested product has not been found.
     * @var bool
     */
    protected $isNotFound;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'kodzero.posmall::lang.components.product.details.name',
            'description' => 'kodzero.posmall::lang.components.product.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        $langPrefix = 'kodzero.posmall::lang.components.product.properties';

        return [
            'product' => [
                'title' => 'kodzero.posmall::lang.common.product',
                'default' => ':slug',
                'type' => 'dropdown',
            ],
            'variant' => [
                'title' => 'kodzero.posmall::lang.common.variant',
                'default' => ':slug',
                'depends' => ['product'],
                'type' => 'dropdown',
            ],
            'redirectOnPropertyChange' => [
                'title' => $langPrefix . '.redirectOnPropertyChange.title',
                'description' => $langPrefix . '.redirectOnPropertyChange.description',
                'default' => 0,
                'type' => 'checkbox',
            ],
            'filterOutOfStock' => [
                'title' => $langPrefix . '.filterOutOfStock.title',
                'description' => $langPrefix . '.filterOutOfStock.description',
                'type' => 'checkbox',
                'default' => 0,
            ],
            'currentVariantReviewsOnly' => [
                'title' => 'kodzero.posmall::lang.components.productReviews.properties.currentVariantReviewsOnly.title',
                'description' => 'kodzero.posmall::lang.components.productReviews.properties.currentVariantReviewsOnly.description',
                'type' => 'checkbox',
                'default' => 0,
            ],
        ];
    }

    /**
     * Options array for the products dropdown.
     *
     * @return array
     */
    public function getProductOptions()
    {
        return [':slug' => trans('kodzero.posmall::lang.components.products.properties.use_url')]
            + ProductModel::get()->pluck('name', 'id')->toArray();
    }

    /**
     * Options array for the variants dropdown.
     *
     * @return array
     */
    public function getVariantOptions()
    {
        $product = Request::input('product');

        if (! $product || $product === ':slug') {
            return [':slug' => trans('kodzero.posmall::lang.components.products.properties.use_url')];
        }

        return [':slug' => trans('kodzero.posmall::lang.components.products.properties.use_url')]
            + ProductModel::find($product)->variants->pluck('name', 'id')->toArray();
    }

    /**
     * The component is executed.
     *
     * @return string|void
     */
    public function onRun()
    {
        if ($this->isNotFound) {
            return $this->controller->run('404');
        }

        $description = $this->item->description_short ?: $this->item->description;

        $this->page->title = $this->item->meta_title ?: $this->item->name;
        $this->page->meta_description = $this->item->meta_description
            ?: Str::limit($this->cleanMetaText($description), 160);
        $this->page->meta_description = $this->cleanMetaText($this->page->meta_description);
        $this->page['posmallJsonLd'] = $this->productJsonLd();
    }

    /**
     * The component is initialized.
     *
     * @return void
     */
    public function init()
    {
        try {
            $this->setVar('item', $this->getItem());
            $this->setVar('variants', $this->getVariants());
            $this->setVar('galleryImages', $this->getGalleryImages());
        } catch (ModelNotFoundException $e) {
            $this->isNotFound = true;

            return;
        }

        if (! $this->product->categories) {
            $this->isNotFound = true;
            logger()->error(
                'A product without an existing category has been found.',
                ['id' => $this->item->id, 'name' => $this->item->name]
            );

            return;
        }

        $this->showReviews = (bool)ReviewSettings::get('enabled', false);
        $this->addComponent(
            ProductReviews::class,
            'productReviews',
            [
                'product' => $this->product->id,
                'variant' => optional($this->variant)->id,
                'currentVariantReviewsOnly' => $this->property('currentVariantReviewsOnly'),
            ]
        );
        $this->addComponent(
            WishlistButton::class,
            'wishlistButton',
            [
                'product' => $this->product->id,
                'variant' => optional($this->variant)->id,
            ]
        );

        $this->setVar('variantPropertyValues', $this->getPropertyValues());
        $this->setVar('props', $this->getProps());
        $this->setVar('dataLayer', $this->handleDataLayer());
        $this->setVar('redirectOnPropertyChange', (bool)$this->property('redirectOnPropertyChange'));
    }

    /**
     * Add a product to the cart.
     *
     * @throws ValidationException
     * @return mixed
     */
    public function onAddToCart()
    {
        $validation = Validator::make(input() ?: [], [
            'quantity' => 'bail|nullable|integer|min:1',
            'service_options_per_quantity' => 'nullable|boolean',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $product = $this->getProduct();

        $variant = null;
        $values = $this->validateCustomFields(post('fields', []));

        if ($this->variantId !== null) {
            // In case a Variant is added we have to retrieve the model first by the selected props.
            $variant = $this->getVariantByPropertyValues(post('props'));
        }

        $quantity = (int)input('quantity', $product->quantity_default ?? 1);

        if ($quantity < 1) {
            throw new ValidationException(['quantity' => trans('kodzero.posmall::lang.common.invalid_quantity')]);
        }

        $serviceOptionIds = $this->validateAndGetServiceOptionIds($product);
        $serviceOptionsPerQuantity = $this->serviceOptionsPerQuantity();

        return $this->page['added'] = $this->addToCart($product, $quantity, $variant, $values, $serviceOptionIds, $serviceOptionsPerQuantity);
    }

    /**
     * Add a product to the cart with services.
     *
     * @throws ValidationException
     * @return mixed
     */
    public function onAddToCartWithServices()
    {
        $product = $this->getProduct();

        // Fetch the original cart data from the session.
        $variant = Variant::find(Session::pull('posmall.cart.add.variant'));
        $quantity = Session::pull('posmall.cart.add.quantity');
        $values = Collection::wrap(Session::pull('posmall.cart.add.values', []));
        $values = $values->map(
            fn ($attributes) => CustomFieldValue::make($attributes)
        );

        $serviceOptionIds = $this->validateAndGetServiceOptionIds($product);
        $serviceOptionsPerQuantity = $this->serviceOptionsPerQuantity();

        return $this->page['added'] = $this->addToCart($product, $quantity, $variant, $values, $serviceOptionIds, $serviceOptionsPerQuantity);
    }

    protected function validateAndGetServiceOptionIds(ProductModel $product): array
    {
        if ($product->services->count() === 0) {
            return [];
        }

        $required = $product->services->where('pivot.required', true);
        $rules = $required->mapWithKeys(
            fn ($service) => [
                'service.' . $service->id => 'required|min:1|array',
                'service.' . $service->id . '.*' => 'required|in:' . $service->options->pluck('id')->implode(','),
            ]
        );
        $messages = $required->flatMap(
            fn ($service) => [
                'service.' . $service->id . '.required' => trans('kodzero.posmall::frontend.services.required'),
                'service.' . $service->id . '.min' => trans('kodzero.posmall::frontend.services.required'),
                'service.' . $service->id . '.*.required' => trans('kodzero.posmall::frontend.services.required'),
            ]
        );

        $validation = Validator::make(post(), $rules->toArray(), $messages->toArray());

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        return $this->selectedServiceOptionIds($product);
    }

    protected function selectedServiceOptionIds(ProductModel $product): array
    {
        $selected = collect(post('service', []));
        $validServices = $product->services->keyBy('id');
        $optionIds = collect();

        foreach ($selected as $serviceId => $ids) {
            if (! $validServices->has((int)$serviceId)) {
                throw new ValidationException(['service' => trans('kodzero.posmall::frontend.services.invalid_option')]);
            }

            $service = $validServices->get((int)$serviceId);
            $validOptionIds = $service->options->pluck('id');
            $ids = collect((array)$ids)->filter()->map(fn ($id) => (int)$id);

            if ($ids->diff($validOptionIds)->isNotEmpty()) {
                throw new ValidationException(['service' => trans('kodzero.posmall::frontend.services.invalid_option')]);
            }

            $optionIds = $optionIds->merge($ids);
        }

        return $optionIds->unique()->values()->all();
    }

    /**
     * The user changed a property of the product.
     *
     * Check the stock for the currentyl selected Variant and return
     * the information back to the user.
     *
     * @return array
     */
    public function onChangeProperty()
    {
        $values = post('values', []);
        $isInitial = (bool)post('initial', false);
        $variant = $this->getVariantByPropertyValues($values);

        $this->page['stock'] = $variant ? $variant->stock : 0;
        $this->page['item'] = $variant ?: $this->getProduct();

        if ($this->redirectOnPropertyChange && $isInitial === false) {
            $item = $this->page['item'];
            $slug = $item instanceof Variant ? $item->product->slug : $item->slug;

            return redirect()->to($this->getProductPageUrl($slug, $item));
        }

        return $this->stockCheckResponse('props');
    }

    /**
     * Check the stock for the currently selected item.
     *
     * @throws ValidationException
     * @return array
     */
    public function onCheckProductStock()
    {
        $slug = post('slug');

        if (! $slug) {
            throw new ValidationException(['Missing input data']);
        }

        $item = $this->getItem();

        $this->page['stock'] = $item ? $item->stock : 0;
        $this->page['item'] = $item;

        return $this->stockCheckResponse();
    }

    /**
     * Return the product's new price.
     *
     * @return array
     */
    public function onChangeConfiguration()
    {
        $fields = $this->mapToCustomFields(post('fields', []));
        $values = $this->mapToCustomFieldValues($fields);

        // If we are on a Variant screen make sure to get the
        // Variant by the current property value selection, not
        // by the url parameter.
        if ($this->param('variant')) {
            $propertyValues = post('props', []);
            $item = $this->getVariantByPropertyValues($propertyValues);
        } else {
            $item = $this->getItem();
        }

        // Remove the add to cart button in case the current configuration
        // does not return a product or variant.
        $return = ['.mall-product__add-to-cart' => ''];

        if ($item) {
            $priceData = $item->priceIncludingCustomFieldValues($values);
            $price = Price::fromArray($priceData);

            $partial = $this->renderPartial($this->alias . '::currentprice', ['price' => $price->string]);

            $return = ['.mall-product__current-price' => $partial];
        }

        return $return;
    }

    /**
     * Get the ProductModel.
     *
     * @param array|null $with
     *
     * @return ProductModel
     */
    public function getProduct(?array $with = null): ProductModel
    {
        if ($this->product) {
            return $this->product;
        }

        if ($with === null) {
            $with = [
                'variants',
                'variants.property_values',
                'variants.image_sets',
                'brand',
                'image_sets',
                'downloads',
                'categories',
                'property_values.property.property_groups',
                'custom_fields.custom_field_options',
                'services.options',
                'taxes',
            ];
        }

        $product = $this->property('product');
        $model = ProductModel::published()->with($with);

        if ($product === ':slug') {
            $method = $this->rainlabTranslateInstalled() ? 'transWhere' : 'where';

            $product = $model->$method('slug', $this->param('slug'))->firstOrFail();
            TranslationPreloader::preloadNested($product, ['variants.property_values']);

            return $product;
        }

        $product = $model->where('id', $product)->firstOrFail();
        TranslationPreloader::preloadNested($product, ['variants.property_values']);

        return $product;
    }

    /**
     * Fetch the item to display.
     *
     * This can be either a Product or a Variant depending
     * on the given input values.
     *
     * @return ProductModel|Variant
     */
    protected function getItem()
    {
        $this->product = $this->getProduct();

        // If no Variant is specified as URL parameter the Product
        // model can be returned directly.
        if ($this->product->inventory_management_method !== 'variant') {
            return $this->product;
        }

        $variantId = $this->getConfiguredVariantId();

        // If no usable Variant param is present, use the first published Variant.
        if (! $variantId) {
            $variantId = optional($this->product->variants->where('published', true)->first())->id;
        }

        // If no Variants are available, simply display the Product itself.
        if (! $variantId) {
            return $this->product;
        }

        $this->setVar('variantId', $variantId);

        $variant = Variant::published()
            ->where('product_id', $this->product->id)
            ->where('id', $variantId)
            ->first();

        if (! $variant) {
            $this->variantId = null;
            $this->setVar('variantId', null);

            return $this->product;
        }

        return $this->variant = $variant;
    }

    protected function getConfiguredVariantId(): ?int
    {
        $variantId = $this->property('variant');

        if ($variantId === ':slug' || $variantId === ':variant') {
            $variantId = $this->param('variant');
        }

        return $this->normalizeVariantId($variantId);
    }

    protected function normalizeVariantId($value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        if ($value === '' || Str::startsWith($value, ':')) {
            return null;
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        $decoded = $this->decode($value);

        return is_numeric($decoded) ? (int)$decoded : null;
    }

    /**
     * Get all Variants that belong to this ProductModel.
     *
     * @return Collection
     */
    protected function getVariants(): Collection
    {
        // Single Products won't have any Variants.
        if ($this->product->inventory_management_method === 'single' || ! $this->product->group_by_property_id) {
            return collect();
        }

        return $this->product->variants
            ->where('published', true)
            ->sortBy(fn (Variant $variant) => optional($variant->prices->sortBy('price')->first())->price ?? PHP_INT_MAX)
            ->groupBy(fn (Variant $variant) => $this->getGroupedPropertyValue($variant));
    }

    protected function getGalleryImages(): Collection
    {
        $images = Collection::make($this->product->all_images ?? []);

        if ($this->variant) {
            $images = $images->merge(Collection::make($this->variant->all_images ?? []));
        }

        return $images->filter()->unique('id')->values();
    }

    /**
     * Add a product to the cart and refresh all related partials.
     *
     * @param ProductModel $product
     * @param $quantity
     * @param $variant
     * @param $values
     * @param array $serviceOptions
     *
     * @throws ValidationException
     * @return array|RedirectResponse
     */
    protected function addToCart(ProductModel $product, $quantity, $variant, $values, array $serviceOptions = [], bool $serviceOptionsPerQuantity = true)
    {
        $cart = Cart::byUser(Auth::user());

        $serviceOptions = array_filter($serviceOptions);

        try {
            $cartProduct = $cart->addProduct($product, $quantity, $variant, $values, $serviceOptions, $serviceOptionsPerQuantity);
        } catch (OutOfStockException $e) {
            throw new ValidationException(['quantity' => trans('kodzero.posmall::lang.common.stock_limit_reached')]);
        }

        $cart->load('products');

        // If the redirect_to_cart option is set to true the user is redirected to the cart.
        if ((bool)GeneralSettings::get('redirect_to_cart', false) === true) {
            $cartPage = GeneralSettings::get('cart_page');

            return Redirect::to($this->controller->pageUrl($cartPage));
        }

        Flash::success(trans('kodzero.posmall::frontend.cart.added'));

        return [
            'product' => $product->only($this->getPublicProductAttributes()),
            'variant' => optional($variant)->only($this->getPublicProductAttributes()),
            'item' => $this->dataLayerArray($product, $variant),
            'currency' => optional(Currency::activeCurrency())->only('symbol', 'code', 'rate', 'decimals'),
            'quantity' => $quantity,
            'new_items_count' => optional($cart->products)->count() ?? 0,
            'new_items_quantity' => optional($cart->products)->sum('quantity') ?? 0,
            'cart' => $cart->only($this->getPublicCartAttributes()),
            'cart_product' => $cartProduct->only($this->getPublicCartProductAttributes()),
            'added' => true,
        ];
    }

    /**
     * Defines what attributes are returned as JSON when a product was added to the cart.
     *
     * @return string[]
     */
    protected function getPublicProductAttributes(): array
    {
        return [
            'hash_id',
            'user_defined_id',
            'name',
            'slug',
            'description_short',
            'description',
            'is_virtual',
            'images',
            'main_image',
            'all_images',
            'properties_description',
        ];
    }

    /**
     * Defines what cart attributes are returned as JSON when a product was added to the cart.
     *
     * @return string[]
     */
    protected function getPublicCartAttributes(): array
    {
        return ['customer', 'payment_method', 'shipping_address'];
    }

    /**
     * Defines what cart product attributes are returned as JSON when a product was added to the cart.
     *
     * @return string[]
     */
    protected function getPublicCartProductAttributes(): array
    {
        return ['quantity', 'weight', 'price', 'hashid', 'custom_field_value_description'];
    }

    protected function serviceOptionsPerQuantity(): bool
    {
        return filter_var(input('service_options_per_quantity', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the PropertyValue this Variant is grouped by.
     *
     * @param Variant $variant
     *
     * @return mixed
     */
    protected function getGroupedPropertyValue(Variant $variant)
    {
        $property = $this->getGroupedProperty($variant);

        return \is_array($property->value) ? json_encode($property->value) : $property->value;
    }

    /**
     * Get the Property this Variant is grouped by.
     *
     * @param Variant $variant
     *
     * @return PropertyValue|object
     */
    protected function getGroupedProperty(Variant $variant)
    {
        if (!$variant->product || !$variant->product->group_by_property_id) {
            return (object)['value' => 0];
        }

        return $variant->property_values->first(
            fn (PropertyValue $value) => $value->property_id === $variant->product->group_by_property_id
        );
    }

    /**
     * Get all Properties of this item.
     *
     * @return Collection
     */
    protected function getProps()
    {
        $valueMap = $this->getValueMap();

        if ($valueMap->count() < 1) {
            return $valueMap;
        }

        return $this->product->categories->flatMap->properties->map(function (Property $property) use ($valueMap) {
            
             $filteredValues = optional($valueMap->get($property->id))->filter(function ($value) {
                // Reject values where variant_id is null when we have a variant
                if ($this->variant && $value->variant_id === null) {
                    return false;
                }
                
                // Filter out property values where the associated variant has zero stock (if enabled)
                if ((bool)$this->property('filterOutOfStock')) {
                    return $value->variant->stock > 0 || $value->variant->allow_out_of_stock_purchases;
                }
                
                return true;
            });


            // Reorder values based on property options if it's a dropdown
            if ($property->type === 'dropdown' && $property->options && $filteredValues) {
                $order = collect($property->options)->pluck('value')->filter()->flip();
                $filteredValues = $filteredValues->sortBy(fn ($value) => $order->get($value->value, 999999));
            }
            
            return (object)[
                'property' => $property,
                'values' => optional($filteredValues)->unique('value'),
            ];
        })->filter(function ($collection) {
            if ($this->variant && (bool)$collection->property->pivot->use_for_variants !== true) {
                return false;
            }

            return $collection->values && $collection->values->count() > 0;
        })->keyBy(fn ($value) => $value->property->id);
    }

    /**
     * Get a map of all PropertyValues.
     *
     * The key is the property_id, the value is the PropertyValue model.
     *
     * @return Collection
     */
    protected function getValueMap()
    {
        if (! $this->variant) {
            return collect([]);
        }

        $groupedValue = $this->getGroupedPropertyValue($this->variant);

        if ($groupedValue === null) {
            return collect([]);
        }

        $values = PropertyValue::where('product_id', $this->product->id)
            ->where('value', '<>', '')
            ->whereNotNull('value')
            ->when($groupedValue > 0 && $this->redirectOnPropertyChange, function ($q) use ($groupedValue) {
                $q->where('value', '<>', $groupedValue);
            })
            ->get();

        TranslationPreloader::preload($values);

        return $values->groupBy('property_id');
    }

    /**
     * Find a Variant by a set of PropertyValue ids.
     *
     * @param $valueIds
     *
     * @return null
     */
    protected function getVariantByPropertyValues($valueIds)
    {
        $ids = collect($valueIds)->map(
            fn ($id) => $this->decode($id)
        );

        $product = $this->getProduct([]);

        $value = (new VariantByPropertyValuesQuery($product, $ids))->query()->first();

        return $value ? $value->variant : null;
    }

    /**
     * Return all PropertyValues of the current Variant.
     *
     * @return Collection
     */
    protected function getPropertyValues()
    {
        if (! $this->variant) {
            return collect([]);
        }

        return $this->variant->property_values->keyBy('property_id');
    }

    /**
     * Return the currently available stock information back to the user.
     *
     * @return array
     */
    protected function stockCheckResponse(string $customFieldsPostKey = 'fields'): array
    {
        // Make sure reviews are fetched correctly.
        $reviews = $this->controller->findComponentByName('productReviews');

        if ($reviews) {
            $reviews->onRun();
        }

        $data = [
            'stock' => $this->page['stock'],
            'item' => $this->page['item'],
        ];

        // Factor in currently selected custom field values in the displayed price.
        // Product variant properties are posted as "props" and must not be
        // interpreted as custom fields during the stock check.
        $fields = $this->mapToCustomFields(post($customFieldsPostKey, []));
        $values = $this->mapToCustomFieldValues($fields);
        $priceData = $data['item']->priceIncludingCustomFieldValues($values);
        $data['price'] = $this->page['price'] = Price::fromArray($priceData);

        return [
            '.mall-product__current-price' => $this->renderPartial($this->alias . '::currentprice', $data),
            '.mall-product__info' => $this->renderPartial($this->alias . '::info', $data),
            '.mall-product__add-to-cart' => $this->renderPartial($this->alias . '::addtocart', $data),
        ];
    }

    /**
     * Check if RainLab.Translate is available.
     *
     * @return bool
     */
    protected function rainlabTranslateInstalled(): bool
    {
        return PluginManager::instance()->exists('RainLab.Translate');
    }

    /**
     * Generate the page url for a Product/Variant combination.
     *
     * @param $slug
     * @param Variant|null $item
     *
     * @return string
     */
    private function getProductPageUrl($slug, ?Variant $item): string
    {
        return $this->controller->pageUrl(
            GeneralSettings::get('product_page'),
            [
                'slug' => $slug,
                'variant' => optional($item)->variantHashId,
            ]
        );
    }

    /**
     * Generate Google Tag Manager dataLayer code.
     */
    private function handleDataLayer()
    {
        if (! $this->page->layout->hasComponent('enhancedEcommerceAnalytics')) {
            return;
        }

        $dataLayer = [
            'ecommerce' => [
                'detail' => [
                    'products' => [$this->dataLayerArray()],
                ],
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
            'id' => $item->prefixedId,
            'name' => $product->name,
            'price' => $item->price()->decimal,
            'brand' => optional($item->brand)->name,
            'category' => optional(optional($item->categories)->first())->name,
            'variant' => optional($variant)->name,
        ];
    }

    private function cleanMetaText($value): string
    {
        $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function productJsonLd(): ?string
    {
        $currency = Currency::activeCurrency();

        if (! $this->item || ! $currency) {
            return null;
        }

        $price = $this->item->price();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $this->item->name,
            'url' => request()->url(),
            'offers' => [
                '@type' => 'Offer',
                'price' => $price->decimal,
                'priceCurrency' => $currency->code,
                'url' => request()->url(),
            ],
        ];

        $description = $this->cleanMetaText($this->item->meta_description ?: ($this->item->description_short ?: $this->item->description));
        if ($description !== '') {
            $data['description'] = $description;
        }

        if ($this->item->image) {
            $data['image'] = $this->item->image->path;
        }

        $brand = optional($this->product->brand)->name;
        if ($brand) {
            $data['brand'] = [
                '@type' => 'Brand',
                'name' => $brand,
            ];
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
