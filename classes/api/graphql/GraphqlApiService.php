<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api\GraphQL;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Api\CartApiService;
use KodZero\POSMall\Classes\Api\CatalogApiService;
use KodZero\POSMall\Classes\Api\CheckoutApiService;
use KodZero\POSMall\Classes\Api\CommerceContext;
use KodZero\POSMall\Classes\Api\CustomerApiService;
use KodZero\POSMall\Classes\Api\DiscoveryApiService;
use KodZero\POSMall\Classes\Api\FavoriteListApiService;
use KodZero\POSMall\Classes\Api\OrderApiService;
use KodZero\POSMall\Classes\Api\ReviewsApiService;
use KodZero\POSMall\Classes\Api\ServicesApiService;
use KodZero\POSMall\Classes\Api\TaxPreviewApiService;
use KodZero\POSMall\Models\ApiSettings;
use Throwable;

class GraphqlApiService
{
    public function __construct(
        private readonly CatalogApiService $catalog,
        private readonly DiscoveryApiService $discovery,
        private readonly ServicesApiService $services,
        private readonly FavoriteListApiService $favorites,
        private readonly CartApiService $carts,
        private readonly CustomerApiService $customers,
        private readonly CheckoutApiService $checkout,
        private readonly OrderApiService $orders,
        private readonly ReviewsApiService $reviews,
        private readonly TaxPreviewApiService $taxPreview
    ) {
    }

    public function execute(Request $request, CommerceContext $context): array
    {
        $input = $this->input($request);
        $query = trim((string)($input['query'] ?? ''));

        if ($query === '') {
            return [
                'status' => 400,
                'body' => [
                    'errors' => [[
                        'message' => 'GraphQL query is required.',
                        'extensions' => ['code' => 'graphql_query_required'],
                    ]],
                ],
            ];
        }

        try {
            $result = GraphQL::executeQuery(
                $this->schema($context),
                $query,
                null,
                ['commerceContext' => $context],
                $this->variables($input['variables'] ?? null),
                $this->operationName($input['operationName'] ?? null),
                null,
                $this->validationRules()
            );

            $body = $this->safeResult($result);

            return [
                'status' => $this->statusFromBody($body),
                'body' => $body,
            ];
        } catch (Throwable) {
            return [
                'status' => 500,
                'body' => [
                    'errors' => [[
                        'message' => 'The GraphQL request could not be processed.',
                        'extensions' => ['code' => 'server_error'],
                    ]],
                ],
            ];
        }
    }

    private function schema(CommerceContext $context): Schema
    {
        $categoryType = null;
        $categoryType = new ObjectType([
            'name' => 'POSMallCategory',
            'fields' => static function () use (&$categoryType): array {
                return [
                    'id' => Type::nonNull(Type::int()),
                    'name' => Type::nonNull(Type::string()),
                    'slug' => Type::string(),
                    'children' => Type::listOf($categoryType),
                ];
            },
        ]);

        $contextEntityType = new ObjectType([
            'name' => 'POSMallContextEntity',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'type' => Type::string(),
            ],
        ]);

        $contextType = new ObjectType([
            'name' => 'POSMallCommerceContext',
            'fields' => [
                'vendor' => $contextEntityType,
                'channel' => $contextEntityType,
                'warehouse' => $contextEntityType,
            ],
        ]);

        $statusType = new ObjectType([
            'name' => 'POSMallStatus',
            'fields' => [
                'status' => Type::nonNull(Type::string()),
                'version' => Type::nonNull(Type::string()),
                'context' => $contextType,
            ],
        ]);

        $priceType = new ObjectType([
            'name' => 'POSMallPrice',
            'fields' => [
                'currency' => Type::string(),
                'integer' => Type::int(),
                'decimal' => Type::float(),
                'formatted' => Type::string(),
            ],
        ]);

        $imageType = new ObjectType([
            'name' => 'POSMallImage',
            'fields' => [
                'jpeg' => Type::string(),
                'webp' => Type::string(),
                'alt' => Type::string(),
            ],
        ]);

        $productFlagsType = new ObjectType([
            'name' => 'POSMallProductFlags',
            'fields' => [
                'published' => Type::boolean(),
                'virtual' => Type::boolean(),
                'shippable' => Type::boolean(),
                'stackable' => Type::boolean(),
                'onSale' => Type::boolean(),
            ],
        ]);

        $productCardType = new ObjectType([
            'name' => 'POSMallProductCard',
            'fields' => [
                'id' => Type::nonNull(Type::string()),
                'hashId' => Type::string(),
                'type' => Type::string(),
                'productId' => Type::string(),
                'productHashId' => Type::string(),
                'variantId' => Type::string(),
                'variantHashId' => Type::string(),
                'name' => Type::string(),
                'productName' => Type::string(),
                'variantName' => Type::string(),
                'slug' => Type::string(),
                'sku' => Type::string(),
                'descriptionShort' => Type::string(),
                'price' => $priceType,
                'stock' => Type::int(),
                'rating' => Type::float(),
                'image' => $imageType,
                'flags' => $productFlagsType,
            ],
        ]);

        $paginationType = new ObjectType([
            'name' => 'POSMallPagination',
            'fields' => [
                'page' => Type::int(),
                'perPage' => Type::int(),
                'total' => Type::int(),
                'lastPage' => Type::int(),
            ],
        ]);

        $productsResultType = new ObjectType([
            'name' => 'POSMallProductsResult',
            'fields' => [
                'items' => Type::listOf($productCardType),
                'pagination' => $paginationType,
                'sort' => Type::string(),
                'category' => $categoryType,
            ],
        ]);

        $brandType = new ObjectType([
            'name' => 'POSMallBrand',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'description' => Type::string(),
                'website' => Type::string(),
                'productsCount' => Type::int(),
            ],
        ]);

        $propertyValueType = new ObjectType([
            'name' => 'POSMallPropertyValue',
            'fields' => [
                'value' => Type::string(),
                'displayValue' => Type::string(),
            ],
        ]);

        $propertyType = new ObjectType([
            'name' => 'POSMallProperty',
            'fields' => [
                'id' => Type::int(),
                'hashId' => Type::string(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'type' => Type::string(),
                'unit' => Type::string(),
                'optionValues' => Type::listOf(Type::string()),
                'values' => Type::listOf($propertyValueType),
            ],
        ]);

        $propertiesResultType = new ObjectType([
            'name' => 'POSMallPropertiesResult',
            'fields' => [
                'category' => $categoryType,
                'properties' => Type::listOf($propertyType),
            ],
        ]);

        $brandsResultType = new ObjectType([
            'name' => 'POSMallBrandsResult',
            'fields' => [
                'brands' => Type::listOf($brandType),
                'pagination' => $paginationType,
            ],
        ]);

        $entitiesResultType = new ObjectType([
            'name' => 'POSMallContextEntitiesResult',
            'fields' => [
                'items' => Type::listOf($contextEntityType),
                'pagination' => $paginationType,
            ],
        ]);

        $warehouseDetailType = new ObjectType([
            'name' => 'POSMallWarehouseDetail',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'type' => Type::string(),
                'isDefault' => Type::boolean(),
                'stockItems' => Type::int(),
                'stockTotal' => Type::int(),
            ],
        ]);

        $stockProductType = new ObjectType([
            'name' => 'POSMallStockProduct',
            'fields' => [
                'id' => Type::int(),
                'publicId' => Type::string(),
                'name' => Type::string(),
                'slug' => Type::string(),
                'type' => Type::string(),
            ],
        ]);

        $stockVariantType = new ObjectType([
            'name' => 'POSMallStockVariant',
            'fields' => [
                'id' => Type::int(),
                'publicId' => Type::string(),
                'name' => Type::string(),
            ],
        ]);

        $stockType = new ObjectType([
            'name' => 'POSMallWarehouseStock',
            'fields' => [
                'stock' => Type::int(),
                'product' => $stockProductType,
                'variant' => $stockVariantType,
            ],
        ]);

        $warehouseStockResultType = new ObjectType([
            'name' => 'POSMallWarehouseStockResult',
            'fields' => [
                'warehouse' => $contextEntityType,
                'stock' => Type::listOf($stockType),
                'pagination' => $paginationType,
            ],
        ]);

        $serviceMetaType = new ObjectType([
            'name' => 'POSMallServiceMeta',
            'fields' => [
                'title' => Type::string(),
                'description' => Type::string(),
                'keywords' => Type::string(),
            ],
        ]);

        $serviceOptionType = new ObjectType([
            'name' => 'POSMallServiceOption',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'description' => Type::string(),
                'price' => $priceType,
            ],
        ]);

        $serviceContextType = new ObjectType([
            'name' => 'POSMallServiceContext',
            'fields' => [
                'vendor' => $contextEntityType,
                'channel' => $contextEntityType,
            ],
        ]);

        $serviceType = new ObjectType([
            'name' => 'POSMallService',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'code' => Type::string(),
                'description' => Type::string(),
                'meta' => $serviceMetaType,
                'context' => $serviceContextType,
                'images' => Type::listOf($imageType),
                'options' => Type::listOf($serviceOptionType),
            ],
        ]);

        $servicesResultType = new ObjectType([
            'name' => 'POSMallServicesResult',
            'fields' => [
                'items' => Type::listOf($serviceType),
                'pagination' => $paginationType,
            ],
        ]);

        $productDetailType = new ObjectType([
            'name' => 'POSMallProductDetail',
            'fields' => [
                'item' => $productCardType,
                'brand' => $brandType,
                'categories' => Type::listOf($categoryType),
                'description' => Type::string(),
                'images' => Type::listOf($imageType),
                'properties' => Type::listOf($propertyType),
                'variants' => Type::listOf($productCardType),
                'services' => Type::listOf($serviceType),
            ],
        ]);

        $brandProductsResultType = new ObjectType([
            'name' => 'POSMallBrandProductsResult',
            'fields' => [
                'brand' => $brandType,
                'items' => Type::listOf($productCardType),
                'pagination' => $paginationType,
                'sort' => Type::string(),
                'category' => $categoryType,
            ],
        ]);

        $vendorProductsResultType = new ObjectType([
            'name' => 'POSMallVendorProductsResult',
            'fields' => [
                'vendor' => $contextEntityType,
                'items' => Type::listOf($productCardType),
                'pagination' => $paginationType,
                'sort' => Type::string(),
                'category' => $categoryType,
            ],
        ]);

        $favoriteListItemType = new ObjectType([
            'name' => 'POSMallFavoriteListItem',
            'fields' => [
                'id' => Type::int(),
                'hashId' => Type::string(),
                'quantity' => Type::int(),
                'item' => $productCardType,
            ],
        ]);

        $favoriteListType = new ObjectType([
            'name' => 'POSMallFavoriteList',
            'fields' => [
                'id' => Type::int(),
                'hashId' => Type::string(),
                'name' => Type::string(),
                'itemsCount' => Type::int(),
                'items' => Type::listOf($favoriteListItemType),
            ],
        ]);

        $favoriteListsResultType = new ObjectType([
            'name' => 'POSMallFavoriteListsResult',
            'fields' => [
                'favoriteLists' => Type::listOf($favoriteListType),
            ],
        ]);

        $addressType = new ObjectType([
            'name' => 'POSMallAddress',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'company' => Type::string(),
                'lines' => Type::string(),
                'zip' => Type::string(),
                'city' => Type::string(),
                'countryId' => Type::int(),
                'stateId' => Type::int(),
                'details' => Type::string(),
                'deliveryNotes' => Type::string(),
            ],
        ]);

        $cartMethodType = new ObjectType([
            'name' => 'POSMallCartMethod',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'code' => Type::string(),
                'price' => Type::string(),
            ],
        ]);

        $cartDiscountType = new ObjectType([
            'name' => 'POSMallCartDiscount',
            'fields' => [
                'id' => Type::int(),
                'code' => Type::string(),
                'name' => Type::string(),
            ],
        ]);

        $cartServiceOptionType = new ObjectType([
            'name' => 'POSMallCartServiceOption',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'price' => Type::string(),
            ],
        ]);

        $cartItemTotalType = new ObjectType([
            'name' => 'POSMallCartItemTotal',
            'fields' => [
                'preTaxes' => Type::int(),
                'taxes' => Type::int(),
                'postTaxes' => Type::int(),
            ],
        ]);

        $cartItemType = new ObjectType([
            'name' => 'POSMallCartItem',
            'fields' => [
                'id' => Type::int(),
                'hashId' => Type::string(),
                'itemId' => Type::string(),
                'productId' => Type::string(),
                'variantId' => Type::string(),
                'name' => Type::string(),
                'productName' => Type::string(),
                'variantName' => Type::string(),
                'quantity' => Type::int(),
                'image' => $imageType,
                'price' => $priceType,
                'total' => $cartItemTotalType,
                'serviceOptions' => Type::listOf($cartServiceOptionType),
            ],
        ]);

        $cartTotalsType = new ObjectType([
            'name' => 'POSMallCartTotals',
            'fields' => [
                'productPreTaxes' => Type::int(),
                'productTaxes' => Type::int(),
                'productPostTaxes' => Type::int(),
                'shippingPostTaxes' => Type::int(),
                'paymentPostTaxes' => Type::int(),
                'totalTaxes' => Type::int(),
                'totalPostTaxes' => Type::int(),
            ],
        ]);

        $cartType = new ObjectType([
            'name' => 'POSMallCart',
            'fields' => [
                'id' => Type::int(),
                'customerId' => Type::int(),
                'items' => Type::listOf($cartItemType),
                'itemsCount' => Type::int(),
                'itemsQuantity' => Type::int(),
                'currency' => Type::string(),
                'shippingAddress' => $addressType,
                'billingAddress' => $addressType,
                'shippingMethod' => $cartMethodType,
                'paymentMethod' => $cartMethodType,
                'discounts' => Type::listOf($cartDiscountType),
                'totals' => $cartTotalsType,
            ],
        ]);

        $cartMutationResultType = new ObjectType([
            'name' => 'POSMallCartMutationResult',
            'fields' => [
                'cart' => $cartType,
                'addedItem' => $cartItemType,
            ],
        ]);

        $cartMethodsResultType = new ObjectType([
            'name' => 'POSMallCartMethodsResult',
            'fields' => [
                'methods' => Type::listOf($cartMethodType),
            ],
        ]);

        $taxPreviewType = new ObjectType([
            'name' => 'POSMallTaxPreview',
            'fields' => [
                'cartId' => Type::int(),
                'customerId' => Type::int(),
                'currency' => Type::string(),
                'productTaxes' => Type::int(),
                'totalTaxes' => Type::int(),
                'productPreTaxes' => Type::int(),
                'productPostTaxes' => Type::int(),
                'shippingPostTaxes' => Type::int(),
                'paymentPostTaxes' => Type::int(),
                'totalPostTaxes' => Type::int(),
            ],
        ]);

        $customerType = new ObjectType([
            'name' => 'POSMallCustomer',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'firstname' => Type::string(),
                'lastname' => Type::string(),
                'email' => Type::string(),
                'defaultShippingAddressId' => Type::int(),
                'defaultBillingAddressId' => Type::int(),
                'addresses' => Type::listOf($addressType),
            ],
        ]);

        $customerAddressesResultType = new ObjectType([
            'name' => 'POSMallCustomerAddressesResult',
            'fields' => [
                'addresses' => Type::listOf($addressType),
            ],
        ]);

        $orderStateType = new ObjectType([
            'name' => 'POSMallOrderState',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'flag' => Type::string(),
            ],
        ]);

        $orderPaymentMethodType = new ObjectType([
            'name' => 'POSMallOrderPaymentMethod',
            'fields' => [
                'id' => Type::int(),
                'name' => Type::string(),
                'code' => Type::string(),
            ],
        ]);

        $orderItemType = new ObjectType([
            'name' => 'POSMallOrderItem',
            'fields' => [
                'id' => Type::int(),
                'productId' => Type::int(),
                'variantId' => Type::int(),
                'name' => Type::string(),
                'variantName' => Type::string(),
                'quantity' => Type::int(),
                'totalPostTaxes' => Type::int(),
            ],
        ]);

        $orderTotalsType = new ObjectType([
            'name' => 'POSMallOrderTotals',
            'fields' => [
                'productPostTaxes' => Type::int(),
                'shippingPostTaxes' => Type::int(),
                'paymentPostTaxes' => Type::int(),
                'taxes' => Type::int(),
                'postTaxes' => Type::int(),
            ],
        ]);

        $orderType = new ObjectType([
            'name' => 'POSMallOrder',
            'fields' => [
                'id' => Type::int(),
                'hashId' => Type::string(),
                'orderNumber' => Type::string(),
                'apiSource' => Type::string(),
                'paymentState' => Type::string(),
                'paymentStateLabel' => Type::string(),
                'orderState' => $orderStateType,
                'paymentMethod' => $orderPaymentMethodType,
                'items' => Type::listOf($orderItemType),
                'totals' => $orderTotalsType,
                'context' => $contextType,
                'createdAt' => Type::string(),
            ],
        ]);

        $ordersResultType = new ObjectType([
            'name' => 'POSMallOrdersResult',
            'fields' => [
                'orders' => Type::listOf($orderType),
                'pagination' => $paginationType,
            ],
        ]);

        $paymentLinkType = new ObjectType([
            'name' => 'POSMallPaymentLink',
            'fields' => [
                'url' => Type::string(),
                'expiresAt' => Type::string(),
                'reused' => Type::boolean(),
                'note' => Type::string(),
            ],
        ]);

        $checkoutOrderResultType = new ObjectType([
            'name' => 'POSMallCheckoutOrderResult',
            'fields' => [
                'created' => Type::boolean(),
                'order' => $orderType,
                'paymentLink' => $paymentLinkType,
            ],
        ]);

        $reviewCategoryRatingType = new ObjectType([
            'name' => 'POSMallReviewCategoryRating',
            'fields' => [
                'reviewCategoryId' => Type::int(),
                'reviewCategory' => Type::string(),
                'rating' => Type::int(),
                'approved' => Type::boolean(),
            ],
        ]);

        $reviewType = new ObjectType([
            'name' => 'POSMallReview',
            'fields' => [
                'id' => Type::int(),
                'rating' => Type::int(),
                'title' => Type::string(),
                'description' => Type::string(),
                'pros' => Type::listOf(Type::string()),
                'cons' => Type::listOf(Type::string()),
                'customerName' => Type::string(),
                'approved' => Type::boolean(),
                'categoryRatings' => Type::listOf($reviewCategoryRatingType),
                'createdAt' => Type::string(),
            ],
        ]);

        $productReviewSummaryType = new ObjectType([
            'name' => 'POSMallProductReviewSummary',
            'fields' => [
                'id' => Type::string(),
                'slug' => Type::string(),
                'rating' => Type::float(),
            ],
        ]);

        $reviewsResultType = new ObjectType([
            'name' => 'POSMallReviewsResult',
            'fields' => [
                'product' => $productReviewSummaryType,
                'reviews' => Type::listOf($reviewType),
                'pagination' => $paginationType,
            ],
        ]);

        $createReviewResultType = new ObjectType([
            'name' => 'POSMallCreateReviewResult',
            'fields' => [
                'review' => $reviewType,
                'moderated' => Type::boolean(),
            ],
        ]);

        $serviceCartResultType = new ObjectType([
            'name' => 'POSMallServiceCartResult',
            'fields' => [
                'service' => $serviceType,
                'cart' => $cartType,
            ],
        ]);

        return new Schema([
            'query' => new ObjectType([
                'name' => 'POSMallQuery',
                'fields' => [
                    'status' => [
                        'type' => $statusType,
                        'resolve' => fn () => $this->withScope($context, 'catalog:read', fn () => $this->status($context)),
                    ],
                    'categories' => [
                        'type' => Type::listOf($categoryType),
                        'resolve' => fn () => $this->withScope($context, 'catalog:read', fn () => $this->catalog->categories()),
                    ],
                    'products' => [
                        'type' => $productsResultType,
                        'args' => $this->productQueryArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->products($args, $context)),
                    ],
                    'product' => [
                        'type' => $productDetailType,
                        'args' => ['slug' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->product((string)$args['slug'], $context)),
                    ],
                    'brands' => [
                        'type' => $brandsResultType,
                        'args' => $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->brands($args)),
                    ],
                    'brand' => [
                        'type' => $brandType,
                        'args' => ['slug' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->brand((string)$args['slug'])),
                    ],
                    'brandProducts' => [
                        'type' => $brandProductsResultType,
                        'args' => ['slug' => Type::nonNull(Type::string())] + $this->productQueryArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->brandProducts($args, $context)),
                    ],
                    'properties' => [
                        'type' => $propertiesResultType,
                        'args' => [
                            'category' => Type::string(),
                            'includeChildren' => Type::boolean(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->properties($args)),
                    ],
                    'vendors' => [
                        'type' => $entitiesResultType,
                        'args' => $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->entities('vendors', $this->discovery->vendors($this->queryFromArgs($args) + ['context' => $context]))),
                    ],
                    'vendor' => [
                        'type' => $contextEntityType,
                        'args' => ['slug' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->entity($this->discovery->vendorBySlug((string)$args['slug'], $context)['vendor'] ?? null)),
                    ],
                    'vendorProducts' => [
                        'type' => $vendorProductsResultType,
                        'args' => ['slug' => Type::nonNull(Type::string())] + $this->productQueryArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->vendorProducts($args, $context)),
                    ],
                    'channels' => [
                        'type' => $entitiesResultType,
                        'args' => $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->entities('channels', $this->discovery->channels($this->queryFromArgs($args) + ['context' => $context]))),
                    ],
                    'channel' => [
                        'type' => $contextEntityType,
                        'args' => ['slug' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->entity($this->discovery->channelBySlug((string)$args['slug'], $context)['channel'] ?? null)),
                    ],
                    'warehouses' => [
                        'type' => $entitiesResultType,
                        'args' => $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->entities('warehouses', $this->discovery->warehouses($this->queryFromArgs($args) + ['context' => $context]))),
                    ],
                    'warehouse' => [
                        'type' => $warehouseDetailType,
                        'args' => ['slug' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->warehouse($this->discovery->warehouseBySlug((string)$args['slug'], $context)['warehouse'] ?? null)),
                    ],
                    'warehouseStock' => [
                        'type' => $warehouseStockResultType,
                        'args' => ['slug' => Type::nonNull(Type::string())] + $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->warehouseStock($args, $context)),
                    ],
                    'services' => [
                        'type' => $servicesResultType,
                        'args' => $this->pageArgs(),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->services($args, $context)),
                    ],
                    'service' => [
                        'type' => $serviceType,
                        'args' => ['code' => Type::nonNull(Type::string())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->service((string)$args['code'], $context)),
                    ],
                    'favoriteLists' => [
                        'type' => $favoriteListsResultType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'favorites:read', fn () => $this->favoriteLists((int)$args['customerId'], $context)),
                    ],
                    'cart' => [
                        'type' => $cartType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:read', fn () => $this->cart((int)$args['customerId'], $context)),
                    ],
                    'cartShippingMethods' => [
                        'type' => $cartMethodsResultType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:read', fn () => $this->cartMethods('shipping', (int)$args['customerId'], $context)),
                    ],
                    'cartPaymentMethods' => [
                        'type' => $cartMethodsResultType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:read', fn () => $this->cartMethods('payment', (int)$args['customerId'], $context)),
                    ],
                    'cartTaxPreview' => [
                        'type' => $taxPreviewType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:read', fn () => $this->cartTaxPreview((int)$args['customerId'], $context)),
                    ],
                    'customer' => [
                        'type' => $customerType,
                        'args' => ['id' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'customers:read', fn () => $this->customer((int)$args['id'], $context)),
                    ],
                    'customerAddresses' => [
                        'type' => $customerAddressesResultType,
                        'args' => ['customerId' => Type::nonNull(Type::int())],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'customers:read', fn () => $this->customerAddresses((int)$args['customerId'], $context)),
                    ],
                    'orders' => [
                        'type' => $ordersResultType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'page' => Type::int(),
                            'perPage' => Type::int(),
                            'paymentState' => Type::string(),
                            'orderStateId' => Type::int(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'orders:read', fn () => $this->orders($args, $context)),
                    ],
                    'order' => [
                        'type' => $orderType,
                        'args' => [
                            'hash' => Type::nonNull(Type::string()),
                            'customerId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'orders:read', fn () => $this->order((string)$args['hash'], (int)$args['customerId'], $context)),
                    ],
                    'orderStatus' => [
                        'type' => $orderType,
                        'args' => [
                            'hash' => Type::nonNull(Type::string()),
                            'customerId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'orders:read', fn () => $this->orderStatus((string)$args['hash'], (int)$args['customerId'], $context)),
                    ],
                    'productReviews' => [
                        'type' => $reviewsResultType,
                        'args' => [
                            'slug' => Type::nonNull(Type::string()),
                            'page' => Type::int(),
                            'perPage' => Type::int(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'catalog:read', fn () => $this->productReviews($args)),
                    ],
                ],
            ]),
            'mutation' => new ObjectType([
                'name' => 'POSMallMutation',
                'fields' => [
                    'createFavoriteList' => [
                        'type' => $favoriteListType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'name' => Type::nonNull(Type::string()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'favorites:write', fn () => $this->createFavoriteList($args, $context)),
                    ],
                    'addFavoriteItem' => [
                        'type' => $favoriteListType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'listId' => Type::nonNull(Type::int()),
                            'productId' => Type::nonNull(Type::string()),
                            'variantId' => Type::string(),
                            'quantity' => Type::int(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'favorites:write', fn () => $this->addFavoriteItem($args, $context)),
                    ],
                    'removeFavoriteItem' => [
                        'type' => $favoriteListType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'listId' => Type::nonNull(Type::int()),
                            'itemId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'favorites:write', fn () => $this->removeFavoriteItem($args, $context)),
                    ],
                    'addCartItem' => [
                        'type' => $cartMutationResultType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'productId' => Type::nonNull(Type::string()),
                            'variantId' => Type::string(),
                            'quantity' => Type::int(),
                            'serviceOptionIds' => Type::listOf(Type::string()),
                            'serviceOptionsPerQuantity' => Type::boolean(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->addCartItem($args, $context)),
                    ],
                    'setCartItemQuantity' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'itemId' => Type::nonNull(Type::int()),
                            'quantity' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->setCartItemQuantity($args, $context)),
                    ],
                    'removeCartItem' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'itemId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->removeCartItem($args, $context)),
                    ],
                    'applyCartDiscount' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'code' => Type::nonNull(Type::string()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->applyCartDiscount($args, $context)),
                    ],
                    'setCartAddress' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'addressId' => Type::nonNull(Type::int()),
                            'type' => Type::nonNull(Type::string()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->setCartAddress($args, $context)),
                    ],
                    'setCartShippingMethod' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'shippingMethodId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->setCartShippingMethod($args, $context)),
                    ],
                    'setCartPaymentMethod' => [
                        'type' => $cartType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'paymentMethodId' => Type::nonNull(Type::int()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->setCartPaymentMethod($args, $context)),
                    ],
                    'createCustomerAddress' => [
                        'type' => $addressType,
                        'args' => $this->customerAddressArgs(true),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'customers:write', fn () => $this->createCustomerAddress($args, $context)),
                    ],
                    'updateCustomerAddress' => [
                        'type' => $addressType,
                        'args' => $this->customerAddressArgs(false),
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'customers:write', fn () => $this->updateCustomerAddress($args, $context)),
                    ],
                    'createOrder' => [
                        'type' => $checkoutOrderResultType,
                        'args' => [
                            'customerId' => Type::nonNull(Type::int()),
                            'idempotencyKey' => Type::string(),
                            'customerNotes' => Type::string(),
                            'createPaymentLink' => Type::boolean(),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'checkout:write', fn () => $this->createOrder($args, $context)),
                    ],
                    'createReview' => [
                        'type' => $createReviewResultType,
                        'args' => [
                            'productSlug' => Type::nonNull(Type::string()),
                            'customerId' => Type::int(),
                            'rating' => Type::nonNull(Type::int()),
                            'title' => Type::string(),
                            'description' => Type::string(),
                            'pros' => Type::listOf(Type::string()),
                            'cons' => Type::listOf(Type::string()),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'reviews:write', fn () => $this->createReview($args, $context)),
                    ],
                    'addServiceToCart' => [
                        'type' => $serviceCartResultType,
                        'args' => [
                            'code' => Type::nonNull(Type::string()),
                            'customerId' => Type::nonNull(Type::int()),
                            'serviceOptionIds' => Type::nonNull(Type::listOf(Type::nonNull(Type::int()))),
                        ],
                        'resolve' => fn ($root, array $args) => $this->withScope($context, 'cart:write', fn () => $this->addServiceToCart($args, $context)),
                    ],
                ],
            ]),
        ]);
    }

    private function status(CommerceContext $context): array
    {
        return [
            'status' => 'ok',
            'version' => 'v1',
            'context' => [
                'vendor' => $context->vendor ? $context->vendor->only(['id', 'name', 'slug']) : null,
                'channel' => $context->channel ? $context->channel->only(['id', 'name', 'slug', 'type']) : null,
                'warehouse' => $context->warehouse ? $context->warehouse->only(['id', 'name', 'slug', 'type']) : null,
            ],
        ];
    }

    private function products(array $args, CommerceContext $context): array
    {
        $result = $this->catalog->list($this->queryFromArgs($args), $context);

        return [
            'items' => array_map(fn (array $item) => $this->productCard($item), $result['items'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
            'sort' => $result['sort'] ?? null,
            'category' => $result['category'] ?? null,
        ];
    }

    private function product(string $slug, CommerceContext $context): array
    {
        $result = $this->catalog->product($slug, $context);

        return [
            'item' => $this->productCard($result['item'] ?? []),
            'brand' => $this->brandArray($result['brand'] ?? null),
            'categories' => $result['categories'] ?? [],
            'description' => $result['description'] ?? null,
            'images' => $result['images'] ?? [],
            'properties' => array_map(fn (array $property) => $this->productProperty($property), $result['properties'] ?? []),
            'variants' => array_map(fn (array $item) => $this->productCard($item), $result['variants'] ?? []),
            'services' => array_map(fn (array $service) => $this->linkedService($service), $result['services'] ?? []),
        ];
    }

    private function brands(array $args): array
    {
        $result = $this->discovery->brands($this->queryFromArgs($args));

        return [
            'brands' => array_map(fn (array $brand) => $this->brandArray($brand), $result['brands'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function brand(string $slug): ?array
    {
        return $this->brandArray($this->discovery->brandBySlug($slug)['brand'] ?? null);
    }

    private function brandProducts(array $args, CommerceContext $context): array
    {
        $result = $this->discovery->brandProducts((string)$args['slug'], $this->queryFromArgs($args), $context);

        return [
            'brand' => $this->brandArray($result['brand'] ?? null),
            'items' => array_map(fn (array $item) => $this->productCard($item), $result['items'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
            'sort' => $result['sort'] ?? null,
            'category' => $result['category'] ?? null,
        ];
    }

    private function properties(array $args): array
    {
        $result = $this->discovery->properties($this->queryFromArgs($args));

        return [
            'category' => $result['category'] ?? null,
            'properties' => array_map(fn (array $property) => $this->property($property), $result['properties'] ?? []),
        ];
    }

    private function vendorProducts(array $args, CommerceContext $context): array
    {
        $result = $this->discovery->vendorProducts((string)$args['slug'], $this->queryFromArgs($args), $context);

        return [
            'vendor' => $this->entity($result['vendor'] ?? null),
            'items' => array_map(fn (array $item) => $this->productCard($item), $result['items'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
            'sort' => $result['sort'] ?? null,
            'category' => $result['category'] ?? null,
        ];
    }

    private function entities(string $key, array $result): array
    {
        return [
            'items' => array_map(fn (array $item) => $this->entity($item), $result[$key] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function warehouseStock(array $args, CommerceContext $context): array
    {
        $result = $this->discovery->warehouseStock((string)$args['slug'], $this->queryFromArgs($args), $context);

        return [
            'warehouse' => $this->entity($result['warehouse'] ?? null),
            'stock' => array_map(fn (array $item) => $this->stock($item), $result['stock'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function services(array $args, CommerceContext $context): array
    {
        $result = $this->services->list($this->queryFromArgs($args), $context);

        return [
            'items' => array_map(fn (array $service) => $this->serviceArray($service), $result['items'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function service(string $code, CommerceContext $context): ?array
    {
        return $this->serviceArray($this->services->detail($code, $context)['service'] ?? null);
    }

    private function favoriteLists(int $customerId, CommerceContext $context): array
    {
        $result = $this->favorites->list(['customer_id' => $customerId], $context);

        return [
            'favoriteLists' => array_map(
                fn (array $list) => $this->favoriteList($list),
                $result['favorite_lists'] ?? []
            ),
        ];
    }

    private function createFavoriteList(array $args, CommerceContext $context): array
    {
        $result = $this->favorites->create([
            'customer_id' => $args['customerId'] ?? null,
            'name' => $args['name'] ?? null,
        ], $context);

        return $this->favoriteList($result['favorite_list'] ?? []);
    }

    private function addFavoriteItem(array $args, CommerceContext $context): array
    {
        $result = $this->favorites->addItem([
            'customer_id' => $args['customerId'] ?? null,
            'product_id' => $args['productId'] ?? null,
            'variant_id' => $args['variantId'] ?? null,
            'quantity' => $args['quantity'] ?? null,
        ], (int)$args['listId'], $context);

        return $this->favoriteList($result['favorite_list'] ?? []);
    }

    private function removeFavoriteItem(array $args, CommerceContext $context): array
    {
        $result = $this->favorites->removeItem([
            'customer_id' => $args['customerId'] ?? null,
        ], (int)$args['listId'], (int)$args['itemId'], $context);

        return $this->favoriteList($result['favorite_list'] ?? []);
    }

    private function favoriteList(array $list): array
    {
        return [
            'id' => $list['id'] ?? null,
            'hashId' => $list['hash_id'] ?? null,
            'name' => $list['name'] ?? null,
            'itemsCount' => $list['items_count'] ?? null,
            'items' => array_map(fn (array $item) => [
                'id' => $item['id'] ?? null,
                'hashId' => $item['hash_id'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'item' => $this->productCard($item['item'] ?? []),
            ], $list['items'] ?? []),
        ];
    }

    private function cart(int $customerId, CommerceContext $context): array
    {
        $result = $this->carts->get(['customer_id' => $customerId], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function cartMethods(string $type, int $customerId, CommerceContext $context): array
    {
        $input = ['customer_id' => $customerId];
        $result = $type === 'shipping'
            ? $this->carts->shippingMethods($input, $context)
            : $this->carts->paymentMethods($input, $context);

        return [
            'methods' => array_map(fn (array $method) => $this->cartMethod($method), $result['methods'] ?? []),
        ];
    }

    private function cartTaxPreview(int $customerId, CommerceContext $context): array
    {
        $result = $this->taxPreview->cart(['customer_id' => $customerId], $context);

        return $this->taxPreviewArray($result['tax_preview'] ?? []);
    }

    private function addCartItem(array $args, CommerceContext $context): array
    {
        $result = $this->carts->addItem([
            'customer_id' => $args['customerId'] ?? null,
            'product_id' => $args['productId'] ?? null,
            'variant_id' => $args['variantId'] ?? null,
            'quantity' => $args['quantity'] ?? null,
            'service_option_ids' => $args['serviceOptionIds'] ?? null,
            'service_options_per_quantity' => $args['serviceOptionsPerQuantity'] ?? null,
        ], $context);

        return [
            'cart' => $this->cartArray($result['cart'] ?? []),
            'addedItem' => $this->cartItem($result['added_item'] ?? []),
        ];
    }

    private function setCartItemQuantity(array $args, CommerceContext $context): array
    {
        $result = $this->carts->setQuantity([
            'customer_id' => $args['customerId'] ?? null,
            'quantity' => $args['quantity'] ?? null,
        ], (int)$args['itemId'], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function removeCartItem(array $args, CommerceContext $context): array
    {
        $result = $this->carts->removeItem([
            'customer_id' => $args['customerId'] ?? null,
        ], (int)$args['itemId'], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function applyCartDiscount(array $args, CommerceContext $context): array
    {
        $result = $this->carts->applyDiscount([
            'customer_id' => $args['customerId'] ?? null,
            'code' => $args['code'] ?? null,
        ], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function setCartAddress(array $args, CommerceContext $context): array
    {
        $result = $this->carts->setAddress([
            'customer_id' => $args['customerId'] ?? null,
            'address_id' => $args['addressId'] ?? null,
            'type' => $args['type'] ?? null,
        ], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function setCartShippingMethod(array $args, CommerceContext $context): array
    {
        $result = $this->carts->setShippingMethod([
            'customer_id' => $args['customerId'] ?? null,
            'shipping_method_id' => $args['shippingMethodId'] ?? null,
        ], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function setCartPaymentMethod(array $args, CommerceContext $context): array
    {
        $result = $this->carts->setPaymentMethod([
            'customer_id' => $args['customerId'] ?? null,
            'payment_method_id' => $args['paymentMethodId'] ?? null,
        ], $context);

        return $this->cartArray($result['cart'] ?? []);
    }

    private function cartArray(array $cart): array
    {
        return [
            'id' => $cart['id'] ?? null,
            'customerId' => $cart['customer_id'] ?? null,
            'items' => array_map(fn (array $item) => $this->cartItem($item), $cart['items'] ?? []),
            'itemsCount' => $cart['items_count'] ?? null,
            'itemsQuantity' => $cart['items_quantity'] ?? null,
            'currency' => $cart['currency'] ?? null,
            'shippingAddress' => $this->address($cart['shipping_address'] ?? null),
            'billingAddress' => $this->address($cart['billing_address'] ?? null),
            'shippingMethod' => $this->cartMethod($cart['shipping_method'] ?? null),
            'paymentMethod' => $this->cartMethod($cart['payment_method'] ?? null),
            'discounts' => array_map(fn (array $discount) => [
                'id' => $discount['id'] ?? null,
                'code' => $discount['code'] ?? null,
                'name' => $discount['name'] ?? null,
            ], $cart['discounts'] ?? []),
            'totals' => $this->cartTotals($cart['totals'] ?? []),
        ];
    }

    private function cartItem(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'hashId' => $item['hash_id'] ?? null,
            'itemId' => $item['item_id'] ?? null,
            'productId' => $item['product_id'] ?? null,
            'variantId' => $item['variant_id'] ?? null,
            'name' => $item['name'] ?? null,
            'productName' => $item['product_name'] ?? null,
            'variantName' => $item['variant_name'] ?? null,
            'quantity' => $item['quantity'] ?? null,
            'image' => $item['image'] ?? null,
            'price' => $item['price'] ?? null,
            'total' => [
                'preTaxes' => $item['total']['pre_taxes'] ?? null,
                'taxes' => $item['total']['taxes'] ?? null,
                'postTaxes' => $item['total']['post_taxes'] ?? null,
            ],
            'serviceOptions' => array_map(fn (array $option) => [
                'id' => $option['id'] ?? null,
                'name' => $option['name'] ?? null,
                'price' => $option['price'] ?? null,
            ], $item['service_options'] ?? []),
        ];
    }

    private function cartMethod(?array $method): ?array
    {
        if (!$method) {
            return null;
        }

        return [
            'id' => $method['id'] ?? null,
            'name' => $method['name'] ?? null,
            'code' => $method['code'] ?? null,
            'price' => $method['price'] ?? null,
        ];
    }

    private function address(?array $address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'id' => $address['id'] ?? null,
            'name' => $address['name'] ?? null,
            'company' => $address['company'] ?? null,
            'lines' => $address['lines'] ?? null,
            'zip' => $address['zip'] ?? null,
            'city' => $address['city'] ?? null,
            'countryId' => $address['country_id'] ?? null,
            'stateId' => $address['state_id'] ?? null,
            'details' => $address['details'] ?? null,
            'deliveryNotes' => $address['delivery_notes'] ?? null,
        ];
    }

    private function cartTotals(array $totals): array
    {
        return [
            'productPreTaxes' => $totals['product_pre_taxes'] ?? null,
            'productTaxes' => $totals['product_taxes'] ?? null,
            'productPostTaxes' => $totals['product_post_taxes'] ?? null,
            'shippingPostTaxes' => $totals['shipping_post_taxes'] ?? null,
            'paymentPostTaxes' => $totals['payment_post_taxes'] ?? null,
            'totalTaxes' => $totals['total_taxes'] ?? null,
            'totalPostTaxes' => $totals['total_post_taxes'] ?? null,
        ];
    }

    private function taxPreviewArray(array $preview): array
    {
        return [
            'cartId' => $preview['cart_id'] ?? null,
            'customerId' => $preview['customer_id'] ?? null,
            'currency' => $preview['currency'] ?? null,
            'productTaxes' => $preview['product_taxes'] ?? null,
            'totalTaxes' => $preview['total_taxes'] ?? null,
            'productPreTaxes' => $preview['product_pre_taxes'] ?? null,
            'productPostTaxes' => $preview['product_post_taxes'] ?? null,
            'shippingPostTaxes' => $preview['shipping_post_taxes'] ?? null,
            'paymentPostTaxes' => $preview['payment_post_taxes'] ?? null,
            'totalPostTaxes' => $preview['total_post_taxes'] ?? null,
        ];
    }

    private function customer(int $customerId, CommerceContext $context): array
    {
        $result = $this->customers->get($customerId, $context);

        return $this->customerArray($result['customer'] ?? []);
    }

    private function customerAddresses(int $customerId, CommerceContext $context): array
    {
        $result = $this->customers->addresses($customerId, $context);

        return [
            'addresses' => array_map(fn (array $address) => $this->address($address), $result['addresses'] ?? []),
        ];
    }

    private function createCustomerAddress(array $args, CommerceContext $context): array
    {
        $customerId = (int)$args['customerId'];
        $result = $this->customers->createAddress($customerId, $this->addressInput($args), $context);

        return $this->address($result['address'] ?? []) ?? [];
    }

    private function updateCustomerAddress(array $args, CommerceContext $context): array
    {
        $customerId = (int)$args['customerId'];
        $addressId = (int)$args['addressId'];
        $result = $this->customers->updateAddress($customerId, $addressId, $this->addressInput($args), $context);

        return $this->address($result['address'] ?? []) ?? [];
    }

    private function customerArray(array $customer): array
    {
        return [
            'id' => $customer['id'] ?? null,
            'name' => $customer['name'] ?? null,
            'firstname' => $customer['firstname'] ?? null,
            'lastname' => $customer['lastname'] ?? null,
            'email' => $customer['email'] ?? null,
            'defaultShippingAddressId' => $customer['default_shipping_address_id'] ?? null,
            'defaultBillingAddressId' => $customer['default_billing_address_id'] ?? null,
            'addresses' => array_map(fn (array $address) => $this->address($address), $customer['addresses'] ?? []),
        ];
    }

    private function addressInput(array $args): array
    {
        $map = [
            'company' => 'company',
            'name' => 'name',
            'lines' => 'lines',
            'zip' => 'zip',
            'city' => 'city',
            'countryId' => 'country_id',
            'stateId' => 'state_id',
            'details' => 'details',
            'deliveryNotes' => 'delivery_notes',
            'defaultShipping' => 'default_shipping',
            'defaultBilling' => 'default_billing',
        ];

        $input = [];
        foreach ($map as $graphql => $service) {
            if (array_key_exists($graphql, $args)) {
                $input[$service] = $args[$graphql];
            }
        }

        return $input;
    }

    private function createOrder(array $args, CommerceContext $context): array
    {
        $result = $this->checkout->createOrder([
            'customer_id' => $args['customerId'] ?? null,
            'idempotency_key' => $args['idempotencyKey'] ?? null,
            'customer_notes' => $args['customerNotes'] ?? null,
            'create_payment_link' => $args['createPaymentLink'] ?? null,
        ], $context);

        return [
            'created' => $result['created'] ?? null,
            'order' => $this->orderArray($result['order'] ?? []),
            'paymentLink' => $this->paymentLink($result['payment_link'] ?? null),
        ];
    }

    private function orders(array $args, CommerceContext $context): array
    {
        $result = $this->orders->list($this->orderQueryInput($args), $context, (int)$args['customerId']);

        return [
            'orders' => array_map(fn (array $order) => $this->orderArray($order), $result['orders'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function order(string $hash, int $customerId, CommerceContext $context): array
    {
        $result = $this->orders->detail($hash, ['customer_id' => $customerId], $context);

        return $this->orderArray($result['order'] ?? []);
    }

    private function orderStatus(string $hash, int $customerId, CommerceContext $context): array
    {
        $result = $this->orders->status($hash, ['customer_id' => $customerId], $context);

        return $this->orderArray($result['order'] ?? []);
    }

    private function orderQueryInput(array $args): array
    {
        return array_filter([
            'customer_id' => $args['customerId'] ?? null,
            'page' => $args['page'] ?? null,
            'per_page' => $args['perPage'] ?? null,
            'payment_state' => $args['paymentState'] ?? null,
            'order_state_id' => $args['orderStateId'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function orderArray(array $order): array
    {
        return [
            'id' => $order['id'] ?? null,
            'hashId' => $order['hash_id'] ?? null,
            'orderNumber' => $order['order_number'] ?? null,
            'apiSource' => $order['api_source'] ?? null,
            'paymentState' => $order['payment_state'] ?? null,
            'paymentStateLabel' => $order['payment_state_label'] ?? null,
            'orderState' => $order['order_state'] ?? null,
            'paymentMethod' => $order['payment_method'] ?? null,
            'items' => array_map(fn (array $item) => [
                'id' => $item['id'] ?? null,
                'productId' => $item['product_id'] ?? null,
                'variantId' => $item['variant_id'] ?? null,
                'name' => $item['name'] ?? null,
                'variantName' => $item['variant_name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'totalPostTaxes' => $item['total_post_taxes'] ?? null,
            ], $order['items'] ?? []),
            'totals' => [
                'productPostTaxes' => $order['totals']['product_post_taxes'] ?? null,
                'shippingPostTaxes' => $order['totals']['shipping_post_taxes'] ?? null,
                'paymentPostTaxes' => $order['totals']['payment_post_taxes'] ?? null,
                'taxes' => $order['totals']['taxes'] ?? null,
                'postTaxes' => $order['totals']['post_taxes'] ?? null,
            ],
            'context' => [
                'vendor' => $this->entity($order['context']['vendor'] ?? null),
                'channel' => $this->entity($order['context']['channel'] ?? null),
                'warehouse' => $this->entity($order['context']['warehouse'] ?? null),
            ],
            'createdAt' => $order['created_at'] ?? null,
        ];
    }

    private function paymentLink(?array $link): ?array
    {
        if (!$link) {
            return null;
        }

        return [
            'url' => $link['url'] ?? null,
            'expiresAt' => $link['expires_at'] ?? null,
            'reused' => $link['reused'] ?? null,
            'note' => $link['note'] ?? null,
        ];
    }

    private function productReviews(array $args): array
    {
        $result = $this->reviews->list((string)$args['slug'], $this->queryFromArgs($args));

        return [
            'product' => $result['product'] ?? null,
            'reviews' => array_map(fn (array $review) => $this->review($review), $result['reviews'] ?? []),
            'pagination' => $this->pagination($result['pagination'] ?? []),
        ];
    }

    private function createReview(array $args, CommerceContext $context): array
    {
        $result = $this->reviews->create((string)$args['productSlug'], [
            'customer_id' => $args['customerId'] ?? null,
            'rating' => $args['rating'] ?? null,
            'title' => $args['title'] ?? null,
            'description' => $args['description'] ?? null,
            'pros' => $args['pros'] ?? null,
            'cons' => $args['cons'] ?? null,
        ], $context);

        return [
            'review' => $this->review($result['review'] ?? []),
            'moderated' => $result['moderated'] ?? null,
        ];
    }

    private function addServiceToCart(array $args, CommerceContext $context): array
    {
        $service = $this->services->detail((string)$args['code'], $context)['service'] ?? [];
        $result = $this->services->addToCart([
            'customer_id' => $args['customerId'] ?? null,
            'service_id' => $service['id'] ?? null,
            'service_option_ids' => $args['serviceOptionIds'] ?? [],
        ], $context);

        return [
            'service' => $this->serviceArray($result['service'] ?? null),
            'cart' => $this->cartArray($result['cart'] ?? []),
        ];
    }

    private function review(array $review): array
    {
        return [
            'id' => $review['id'] ?? null,
            'rating' => $review['rating'] ?? null,
            'title' => $review['title'] ?? null,
            'description' => $review['description'] ?? null,
            'pros' => $review['pros'] ?? [],
            'cons' => $review['cons'] ?? [],
            'customerName' => $review['customer_name'] ?? null,
            'approved' => $review['approved'] ?? null,
            'categoryRatings' => array_map(fn (array $rating) => [
                'reviewCategoryId' => $rating['review_category_id'] ?? null,
                'reviewCategory' => $rating['review_category'] ?? null,
                'rating' => $rating['rating'] ?? null,
                'approved' => $rating['approved'] ?? null,
            ], $review['category_ratings'] ?? []),
            'createdAt' => $review['created_at'] ?? null,
        ];
    }

    private function productCard(array $item): array
    {
        return [
            'id' => (string)($item['id'] ?? ''),
            'hashId' => $item['hash_id'] ?? null,
            'type' => $item['type'] ?? null,
            'productId' => $item['product_id'] ?? null,
            'productHashId' => $item['product_hash_id'] ?? null,
            'variantId' => $item['variant_id'] ?? null,
            'variantHashId' => $item['variant_hash_id'] ?? null,
            'name' => $item['name'] ?? null,
            'productName' => $item['product_name'] ?? null,
            'variantName' => $item['variant_name'] ?? null,
            'slug' => $item['slug'] ?? null,
            'sku' => $item['sku'] ?? null,
            'descriptionShort' => $item['description_short'] ?? null,
            'price' => $item['price'] ?? null,
            'stock' => $item['stock'] ?? null,
            'rating' => $item['rating'] ?? null,
            'image' => $item['image'] ?? null,
            'flags' => $this->flags($item['flags'] ?? []),
        ];
    }

    private function flags(array $flags): array
    {
        return [
            'published' => $flags['published'] ?? null,
            'virtual' => $flags['virtual'] ?? null,
            'shippable' => $flags['shippable'] ?? null,
            'stackable' => $flags['stackable'] ?? null,
            'onSale' => $flags['on_sale'] ?? null,
        ];
    }

    private function pagination(array $pagination): array
    {
        return [
            'page' => $pagination['page'] ?? null,
            'perPage' => $pagination['per_page'] ?? null,
            'total' => $pagination['total'] ?? null,
            'lastPage' => $pagination['last_page'] ?? null,
        ];
    }

    private function brandArray(?array $brand): ?array
    {
        if (!$brand) {
            return null;
        }

        return [
            'id' => $brand['id'] ?? null,
            'name' => $brand['name'] ?? null,
            'slug' => $brand['slug'] ?? null,
            'description' => $brand['description'] ?? null,
            'website' => $brand['website'] ?? null,
            'productsCount' => $brand['products_count'] ?? null,
        ];
    }

    private function entity(?array $entity): ?array
    {
        if (!$entity) {
            return null;
        }

        return [
            'id' => $entity['id'] ?? null,
            'name' => $entity['name'] ?? null,
            'slug' => $entity['slug'] ?? null,
            'type' => $entity['type'] ?? null,
        ];
    }

    private function warehouse(?array $warehouse): ?array
    {
        if (!$warehouse) {
            return null;
        }

        return [
            'id' => $warehouse['id'] ?? null,
            'name' => $warehouse['name'] ?? null,
            'slug' => $warehouse['slug'] ?? null,
            'type' => $warehouse['type'] ?? null,
            'isDefault' => $warehouse['is_default'] ?? null,
            'stockItems' => $warehouse['stock_items'] ?? null,
            'stockTotal' => $warehouse['stock_total'] ?? null,
        ];
    }

    private function property(array $property): array
    {
        $options = collect($property['options'] ?? [])
            ->map(fn ($value) => is_array($value) ? ($value['value'] ?? $value['label'] ?? null) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (string)$value)
            ->values()
            ->all();

        return [
            'id' => $property['id'] ?? null,
            'hashId' => $property['hash_id'] ?? null,
            'name' => $property['name'] ?? null,
            'slug' => $property['slug'] ?? null,
            'type' => $property['type'] ?? null,
            'unit' => $property['unit'] ?? null,
            'optionValues' => $options,
            'values' => array_map(fn (array $value) => [
                'value' => isset($value['value']) ? (string)$value['value'] : null,
                'displayValue' => $value['display_value'] ?? null,
            ], $property['values'] ?? []),
        ];
    }

    private function productProperty(array $property): array
    {
        return [
            'id' => $property['id'] ?? null,
            'hashId' => null,
            'name' => $property['property'] ?? null,
            'slug' => null,
            'type' => null,
            'unit' => null,
            'optionValues' => [],
            'values' => [[
                'value' => isset($property['value']) ? (string)$property['value'] : null,
                'displayValue' => $property['display_value'] ?? null,
            ]],
        ];
    }

    private function stock(array $stock): array
    {
        return [
            'stock' => $stock['stock'] ?? null,
            'product' => $this->publicIdEntity($stock['product'] ?? null),
            'variant' => $this->publicIdEntity($stock['variant'] ?? null),
        ];
    }

    private function publicIdEntity(?array $entity): ?array
    {
        if (!$entity) {
            return null;
        }

        return [
            'id' => $entity['id'] ?? null,
            'publicId' => $entity['public_id'] ?? null,
            'name' => $entity['name'] ?? null,
            'slug' => $entity['slug'] ?? null,
            'type' => $entity['type'] ?? null,
        ];
    }

    private function serviceArray(?array $service): ?array
    {
        if (!$service) {
            return null;
        }

        return [
            'id' => $service['id'] ?? null,
            'name' => $service['name'] ?? null,
            'code' => $service['code'] ?? null,
            'description' => $service['description'] ?? null,
            'meta' => $service['meta'] ?? null,
            'context' => [
                'vendor' => $this->entity($service['context']['vendor'] ?? null),
                'channel' => $this->entity($service['context']['channel'] ?? null),
            ],
            'images' => $service['images'] ?? [],
            'options' => array_map(fn (array $option) => [
                'id' => $option['id'] ?? null,
                'name' => $option['name'] ?? null,
                'description' => $option['description'] ?? null,
                'price' => $option['price'] ?? null,
            ], $service['options'] ?? []),
        ];
    }

    private function linkedService(array $service): array
    {
        return [
            'id' => $service['id'] ?? null,
            'name' => $service['name'] ?? null,
            'code' => $service['code'] ?? null,
            'description' => null,
            'meta' => null,
            'context' => ['vendor' => null, 'channel' => null],
            'images' => [],
            'options' => array_map(fn (array $option) => [
                'id' => $option['id'] ?? null,
                'name' => $option['name'] ?? null,
                'description' => null,
                'price' => [
                    'formatted' => isset($option['price']) ? (string)$option['price'] : null,
                ],
            ], $service['options'] ?? []),
        ];
    }

    private function pageArgs(): array
    {
        return [
            'page' => Type::int(),
            'perPage' => Type::int(),
        ];
    }

    private function productQueryArgs(): array
    {
        return $this->pageArgs() + [
            'category' => Type::string(),
            'sort' => Type::string(),
            'q' => Type::string(),
            'includeChildren' => Type::boolean(),
            'includeVariants' => Type::boolean(),
        ];
    }

    private function customerAddressArgs(bool $creating): array
    {
        $args = [
            'customerId' => Type::nonNull(Type::int()),
            'company' => Type::string(),
            'name' => Type::string(),
            'lines' => $creating ? Type::nonNull(Type::string()) : Type::string(),
            'zip' => $creating ? Type::nonNull(Type::string()) : Type::string(),
            'city' => $creating ? Type::nonNull(Type::string()) : Type::string(),
            'countryId' => $creating ? Type::nonNull(Type::int()) : Type::int(),
            'stateId' => Type::int(),
            'details' => Type::string(),
            'deliveryNotes' => Type::string(),
            'defaultShipping' => Type::boolean(),
            'defaultBilling' => Type::boolean(),
        ];

        if (!$creating) {
            $args['addressId'] = Type::nonNull(Type::int());
        }

        return $args;
    }

    private function queryFromArgs(array $args): array
    {
        $query = [
            'page' => $args['page'] ?? null,
            'per_page' => $args['perPage'] ?? null,
            'category' => $args['category'] ?? null,
            'sort' => $args['sort'] ?? null,
            'q' => $args['q'] ?? null,
            'include_children' => array_key_exists('includeChildren', $args) ? $args['includeChildren'] : null,
            'include_variants' => array_key_exists('includeVariants', $args) ? $args['includeVariants'] : null,
        ];

        return array_filter($query, static fn ($value) => $value !== null);
    }

    private function validationRules(): array
    {
        return array_replace(DocumentValidator::defaultRules(), [
            DisableIntrospection::class => new DisableIntrospection(
                ApiSettings::graphqlIntrospectionEnabled()
                    ? DisableIntrospection::DISABLED
                    : DisableIntrospection::ENABLED
            ),
            QueryDepth::class => new QueryDepth(ApiSettings::graphqlMaxDepth()),
            QueryComplexity::class => new QueryComplexity(ApiSettings::graphqlMaxComplexity()),
        ]);
    }

    private function safeResult(ExecutionResult $result): array
    {
        $result->setErrorFormatter(fn (Throwable $error) => $this->safeError($error));

        return $result->toArray(DebugFlag::NONE);
    }

    private function safeError(Throwable $error): array
    {
        $previous = $error instanceof Error ? $error->getPrevious() : null;

        if ($previous instanceof AuthorizationException) {
            return [
                'message' => $previous->getMessage() ?: 'The POSMall API token is not allowed to access this resource.',
                'extensions' => ['code' => 'forbidden'],
            ];
        }

        if ($previous instanceof ValidationException) {
            return [
                'message' => $previous->getMessage() ?: 'The submitted POSMall API data is invalid.',
                'extensions' => ['code' => 'validation_failed'],
            ];
        }

        if ($previous instanceof ModelNotFoundException) {
            return [
                'message' => 'The requested POSMall resource was not found.',
                'extensions' => ['code' => 'not_found'],
            ];
        }

        $message = $error instanceof Error && !$previous
            ? $error->getMessage()
            : 'The GraphQL request could not be processed.';

        $safe = [
            'message' => $message,
            'extensions' => ['code' => 'graphql_error'],
        ];

        if ($error instanceof Error) {
            $locations = $error->getLocations();
            if ($locations !== []) {
                $safe['locations'] = array_map(
                    static fn ($location) => $location->toSerializableArray(),
                    $locations
                );
            }

            if ($error->path !== null && $error->path !== []) {
                $safe['path'] = $error->path;
            }
        }

        return $safe;
    }

    private function statusFromBody(array $body): int
    {
        $errors = $body['errors'] ?? [];

        if ($errors === []) {
            return 200;
        }

        $codes = collect($errors)
            ->map(fn (array $error) => $error['extensions']['code'] ?? null)
            ->filter()
            ->values()
            ->all();

        if (in_array('forbidden', $codes, true)) {
            return 403;
        }

        if (in_array('validation_failed', $codes, true)) {
            return 422;
        }

        if (in_array('not_found', $codes, true)) {
            return 404;
        }

        return 400;
    }

    private function input(Request $request): array
    {
        $json = $request->json()->all();

        return array_replace(
            $request->query(),
            $request->request->all(),
            is_array($json) ? $json : []
        );
    }

    private function variables(mixed $variables): ?array
    {
        if (is_array($variables)) {
            return $variables;
        }

        if (is_string($variables) && trim($variables) !== '') {
            $decoded = json_decode($variables, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    private function operationName(mixed $operationName): ?string
    {
        $operationName = is_string($operationName) ? trim($operationName) : '';

        return $operationName !== '' ? $operationName : null;
    }

    private function withScope(CommerceContext $context, string $scope, callable $callback): mixed
    {
        if (!$context->token->hasScope($scope)) {
            throw new AuthorizationException('API token scope is not sufficient.');
        }

        return $callback();
    }
}
