# POSMall REST API v1 Contract Table

This document is public-safe API documentation. It describes controlled external/client API
support and must not include private agent strategy, secrets, real tokens or internal-only plans.

Base path: `/posmall/api/v1`

Current runtime inventory: 45 REST v1 routes.

OpenAPI skeleton: `plugins/kodzero/posmall/docs/openapi-v1.yaml`.

All endpoints require API to be enabled in POSMall settings and require either:

- `Authorization: Bearer posmall_xxx`
- `X-POSMall-API-Key: posmall_xxx`

By default every REST endpoint is key-only. POSMall can optionally define route access rules for
specific REST/GraphQL paths. A route rule can make a path `public` or `password` protected, but it
must reference an existing POSMall API token ID. That token remains the effective permission
profile, so scopes, customer/vendor/channel/warehouse allow-lists, origin checks and rate limits
still apply.

Route rule format in POSMall API settings:

```text
METHOD path mode token_id
```

Examples:

```text
GET /posmall/api/v1/status public 12
GET /posmall/api/v1/products* public 12
POST /posmall/api/graphql password 13
```

Password-protected route rules accept the shared route password through:

- `X-POSMall-Access-Password`
- `access_password` query/body/JSON field

All responses use:

```json
{"ok": true, "data": {}}
```

Errors use:

```json
{"ok": false, "error": {"code": "validation_failed", "message": "The request could not be validated."}}
```

## Token Controls

| Control | Behavior |
|---|---|
| Scopes | A token must include the endpoint's required scope or `*`. The backend permission tree is a readable UI over the same stored scope list. |
| Allowed origins | Optional global and token origin allow-lists protect browser-origin requests. |
| Allowed customer IDs | Optional token allow-list for customer/order/cart/Favorite List operations. |
| Allowed vendor IDs | Optional token allow-list for explicit `X-POSMall-Vendor` context. |
| Allowed channel IDs | Optional token allow-list for explicit `X-POSMall-Channel` context. |
| Allowed warehouse IDs | Optional token allow-list for explicit `X-POSMall-Warehouse` context. |
| Rate limit | Per-token rate limit overrides the default API setting. |
| Route access rules | Optional per-path public/password access. The configured token ID still controls scopes, context allow-lists and rate limits. |

Known runtime scopes:

- `catalog:read`
- `cart:read`
- `cart:write`
- `checkout:write`
- `orders:read`
- `customers:read`
- `customers:write`
- `favorites:read`
- `favorites:write`
- `reviews:write`
- `*` for trusted internal full API access only

## Commerce Context Headers

| Header | Purpose |
|---|---|
| `X-POSMall-Vendor` | Selects seller/owner context by slug. |
| `X-POSMall-Channel` | Selects sales/source channel by slug. |
| `X-POSMall-Warehouse` | Selects stock/fulfillment context by slug. |

If a context header is not provided, POSMall uses default context behavior. If a header is provided
and the token allow-list does not permit it, the API returns `403 context_not_allowed`.

## Endpoint Contract

| Method | Endpoint | Scope | Parameters | Service | Ownership/context checks | Test status |
|---|---|---|---|---|---|---|
| GET | `/status` | `catalog:read` | context headers | `CommerceContext` | token context allow-lists, invalid/revoked/expired token, origin allow-list, insufficient scope, rate limit | focused Dusk |
| GET | `/categories` | `catalog:read` | none | `CatalogApiService::categories()` | token only | pending full contract |
| GET | `/brands` | `catalog:read` | `page`, `per_page` | `DiscoveryApiService::brands()` | token only | focused Dusk |
| GET | `/brands/{slug}` | `catalog:read` | `slug` | `DiscoveryApiService::brandBySlug()` | token only | pending full contract |
| GET | `/brands/{slug}/products` | `catalog:read` | `slug`, catalog query params | `DiscoveryApiService::brandProducts()` | commerce context | pending full contract |
| GET | `/properties` | `catalog:read` | `category`, `with_values` | `DiscoveryApiService::properties()` | token only | focused Dusk |
| GET | `/vendors` | `catalog:read` | `page`, `per_page` | `DiscoveryApiService::vendors()` | token only | focused Dusk |
| GET | `/vendors/{slug}` | `catalog:read` | `slug` | `DiscoveryApiService::vendorBySlug()` | token only | pending full contract |
| GET | `/vendors/{slug}/products` | `catalog:read` | `slug`, catalog query params | `DiscoveryApiService::vendorProducts()` | commerce context | focused Dusk |
| GET | `/channels` | `catalog:read` | `page`, `per_page` | `DiscoveryApiService::channels()` | token only | focused Dusk |
| GET | `/channels/{slug}` | `catalog:read` | `slug` | `DiscoveryApiService::channelBySlug()` | token only | pending full contract |
| GET | `/warehouses` | `catalog:read` | `page`, `per_page` | `DiscoveryApiService::warehouses()` | token only | focused Dusk |
| GET | `/warehouses/{slug}` | `catalog:read` | `slug` | `DiscoveryApiService::warehouseBySlug()` | token only | pending full contract |
| GET | `/warehouses/{slug}/stock` | `catalog:read` | `slug`, `page`, `per_page` | `DiscoveryApiService::warehouseStock()` | token only | focused Dusk |
| GET | `/products` | `catalog:read` | `page`, `per_page`, `category`, `brand`, `sort`, filters | `CatalogApiService::list()` | commerce context | pending full contract |
| GET | `/products/{slug}` | `catalog:read` | `slug` | `CatalogApiService::product()` | commerce context | pending full contract |
| GET | `/products/{slug}/reviews` | `catalog:read` | `slug`, pagination | `ReviewsApiService::list()` | product visibility | pending full contract |
| POST | `/products/{slug}/reviews` | `reviews:write` | review payload | `ReviewsApiService::create()` | review settings/customer validation | pending full contract |
| GET | `/services` | `catalog:read` | `page`, `per_page`, context headers | `ServicesApiService::list()` | vendor/channel context, global services remain visible | focused Dusk |
| GET | `/services/{code}` | `catalog:read` | `code`, context headers | `ServicesApiService::detail()` | vendor/channel context | focused Dusk |
| POST | `/services/{code}/cart` | `cart:write` | `customer_id`, `service_option_ids`, context headers | `ServicesApiService::addToCart()` | customer allow-list, vendor/channel context | focused Dusk |
| GET | `/cart` | `cart:read` | `customer_id` | `CartApiService::get()` | customer allow-list | pending full contract |
| GET | `/cart/shipping-methods` | `cart:read` | `customer_id` | `CartApiService::shippingMethods()` | customer allow-list | pending full contract |
| GET | `/cart/payment-methods` | `cart:read` | `customer_id` | `CartApiService::paymentMethods()` | customer allow-list | pending full contract |
| GET | `/cart/tax-preview` | `cart:read` | `customer_id` | `TaxPreviewApiService::cart()` | customer allow-list, current cart tax state | focused Dusk |
| POST | `/cart/items` | `cart:write` | product/variant/quantity/service options/custom fields | `CartApiService::addItem()` | customer allow-list plus vendor/channel/warehouse product visibility | focused Dusk |
| PATCH | `/cart/items/{item}` | `cart:write` | `customer_id`, `quantity` | `CartApiService::setQuantity()` | customer allow-list | pending full contract |
| DELETE | `/cart/items/{item}` | `cart:write` | `customer_id` | `CartApiService::removeItem()` | customer allow-list | pending full contract |
| POST | `/cart/discounts` | `cart:write` | `customer_id`, `code` | `CartApiService::applyDiscount()` | customer allow-list | pending full contract |
| POST | `/cart/shipping-method` | `cart:write` | `customer_id`, shipping method payload | `CartApiService::setShippingMethod()` | customer allow-list | pending full contract |
| POST | `/cart/payment-method` | `cart:write` | `customer_id`, payment method payload | `CartApiService::setPaymentMethod()` | customer allow-list | pending full contract |
| POST | `/cart/address` | `cart:write` | `customer_id`, `address_id` or address payload | `CartApiService::setAddress()` | customer allow-list | pending full contract |
| POST | `/checkout/orders` | `checkout:write` | `customer_id`, `idempotency_key`, notes, payment-link flag | `CheckoutApiService::createOrder()` | customer/context allow-lists | focused Dusk |
| GET | `/orders` | `orders:read` | `customer_id`, pagination, status/context filters | `OrderApiService::list()` | customer/context allow-lists | focused Dusk |
| GET | `/orders/{hash}` | `orders:read` | `hash`, `customer_id` | `OrderApiService::detail()` | customer/context allow-lists | focused Dusk |
| GET | `/orders/{hash}/status` | `orders:read` | `hash`, `customer_id` | `OrderApiService::status()` | customer/context allow-lists | pending full contract |
| GET | `/customers/{customer}` | `customers:read` | `customer` | `CustomerApiService::get()` | customer allow-list | focused Dusk |
| GET | `/customers/{customer}/addresses` | `customers:read` | `customer` | `CustomerApiService::addresses()` | customer allow-list | partial focused Dusk |
| GET | `/customers/{customer}/orders` | `orders:read` | `customer`, pagination | `OrderApiService::list()` | customer/context allow-lists | focused Dusk |
| POST | `/customers/{customer}/addresses` | `customers:write` | address payload, including `delivery_notes` | `CustomerApiService::createAddress()` | customer allow-list | focused Dusk |
| PATCH | `/customers/{customer}/addresses/{address}` | `customers:write` | address payload, including `delivery_notes` | `CustomerApiService::updateAddress()` | customer/address ownership | focused Dusk |
| GET | `/favorite-lists` | `favorites:read` | `customer_id` | `FavoriteListApiService::list()` | customer allow-list | focused Dusk |
| POST | `/favorite-lists` | `favorites:write` | `customer_id`, `name` | `FavoriteListApiService::create()` | customer allow-list | focused Dusk |
| POST | `/favorite-lists/{list}/items` | `favorites:write` | `customer_id`, product/variant/quantity | `FavoriteListApiService::addItem()` | customer/list ownership | focused Dusk |
| DELETE | `/favorite-lists/{list}/items/{item}` | `favorites:write` | `customer_id` | `FavoriteListApiService::removeItem()` | customer/list/item ownership | focused Dusk |

## Focused Runtime Examples

### Catalog Product List

List products with stable pagination, sorting, filters and optional commerce context:

```http
GET /posmall/api/v1/products?page=1&per_page=24&category=scarves&include_children=1&sort=price-low&color=purple
Authorization: Bearer posmall_xxx
X-POSMall-Vendor: demo-vendor
X-POSMall-Channel: web
X-POSMall-Warehouse: seattle
```

Response shape:

```json
{
  "ok": true,
  "data": {
    "items": [
      {
        "id": "product-123",
        "name": "Purple Bird Wing Shawl",
        "slug": "purple-bird-wing-shawl",
        "price": {"currency": "USD", "integer": 5000, "formatted": "$50.00"},
        "images": [{"jpeg": "/storage/app/media/posmall/cache/images/catalog/jpeg/example.jpg", "webp": "/storage/app/media/posmall/cache/images/catalog/webp/example.webp"}]
      }
    ],
    "pagination": {"page": 1, "per_page": 24, "total": 300000, "last_page": 12500},
    "sort": "price-low",
    "category": {"id": 10, "name": "Scarves", "slug": "scarves"}
  }
}
```

### Product Detail

```http
GET /posmall/api/v1/products/purple-bird-wing-shawl
Authorization: Bearer posmall_xxx
```

The detail response includes product identity, SEO fields, categories, brand, variants, prices,
optimized images, attached services and service options where the storefront product page exposes
the same business choices.

### Services And Standalone Service Cart

List services in the selected vendor/channel context:

```http
GET /posmall/api/v1/services?per_page=12
Authorization: Bearer posmall_xxx
X-POSMall-Vendor: demo-vendor
```

Add a standalone service to a customer's cart:

```json
{
  "customer_id": 1,
  "service_id": 12,
  "service_option_ids": [34]
}
```

Endpoint:

```http
POST /posmall/api/v1/services/diy-production-help/cart
Authorization: Bearer posmall_xxx
X-POSMall-Vendor: demo-vendor
```

If an explicit vendor/channel context is provided, POSMall allows global services and services
assigned to that context. A mismatched scoped service returns `404`.

### Cart Item Lifecycle

Add a product:

```json
{
  "customer_id": 1,
  "product_id": "product-123",
  "variant_id": null,
  "quantity": 2,
  "service_option_ids": [],
  "service_options_per_quantity": true
}
```

Endpoint:

```http
POST /posmall/api/v1/cart/items
Authorization: Bearer posmall_xxx
```

Update quantity:

```http
PATCH /posmall/api/v1/cart/items/456
Authorization: Bearer posmall_xxx
Content-Type: application/json
```

```json
{"customer_id": 1, "quantity": 3}
```

Remove item:

```http
DELETE /posmall/api/v1/cart/items/456
Authorization: Bearer posmall_xxx
Content-Type: application/json
```

```json
{"customer_id": 1}
```

The response returns the current cart resource after every mutation.

### Cart Address, Shipping And Payment

Set shipping/billing address:

```json
{"customer_id": 1, "address_id": 55, "type": "both"}
```

Set shipping method:

```json
{"customer_id": 1, "shipping_method_id": 3}
```

Set payment method:

```json
{"customer_id": 1, "payment_method_id": 2}
```

These endpoints use the same customer ownership and token allow-list checks as the cart item
mutations.

### Cart Tax Preview

```http
GET /posmall/api/v1/cart/tax-preview?customer_id=1
Authorization: Bearer posmall_xxx
```

The tax preview endpoint returns the cart's current tax totals before order creation. It uses the
same cart/customer boundary as the storefront checkout summary.

```json
{
  "ok": true,
  "data": {
    "tax_preview": {
      "cart_id": 10,
      "customer_id": 1,
      "currency": "USD",
      "product_taxes": 825,
      "total_taxes": 825,
      "total_post_taxes": 10825
    }
  }
}
```

### Checkout Idempotency And Payment Link

```http
POST /posmall/api/v1/checkout/orders
Authorization: Bearer posmall_xxx
Idempotency-Key: optional-client-header-if-client-also-sends-body-key
```

```json
{
  "customer_id": 1,
  "idempotency_key": "phone-order-20260609-0001",
  "customer_notes": "Please leave at reception.",
  "create_payment_link": true
}
```

First successful response:

```json
{
  "ok": true,
  "data": {
    "created": true,
    "order": {"hash_id": "abc123", "order_number": 10042},
    "payment_link": {"url": "https://example.test/posmall/pay/sms_pay_xxxxxxxx", "reused": false}
  }
}
```

Retry with the same token, customer, context and `idempotency_key` returns the same order with
`created = false`. POSMall does not expose an already-created bearer payment link again in the
focused contract test; clients should store the first link response.

### Orders And Status

```http
GET /posmall/api/v1/orders?customer_id=1&page=1&per_page=20
Authorization: Bearer posmall_xxx
```

```http
GET /posmall/api/v1/orders/abc123/status?customer_id=1
Authorization: Bearer posmall_xxx
```

The status endpoint returns a compact order status payload: order hash, order number, source,
payment state, order state, commerce context and creation time.

### Customer Address

Create and update address payloads may include delivery instructions:

```json
{
  "name": "Delivery contact",
  "lines": "100 Example Way",
  "zip": "98101",
  "city": "Seattle",
  "country_id": 1,
  "delivery_notes": "Gate code, driveway or private-property delivery instructions.",
  "default_shipping": true
}
```

The API stores `delivery_notes` on the POSMall address row and returns it in the address response.
Invalid address payloads return `422` with `error.code = "validation_failed"` and no stack trace.

### Favorite List

The Favorite List API mirrors the storefront Favorite List business flow:

```json
{"customer_id": 1, "name": "My Favorite List"}
```

Then add a product:

```json
{"customer_id": 1, "product_id": "product-123", "quantity": 2}
```

Removing an item returns the updated Favorite List with the new `items_count`.

## Current Test Proof

- `php artisan route:list --path=posmall/api/v1 --no-ansi` shows 45 REST v1 routes.
- `php artisan dusk tests/Browser/POSMallApiContractTest.php --no-ansi` passed with 15 tests and
  481 assertions.
- `php artisan dusk tests/Browser/POSMallApiMultivendorParityTest.php --no-ansi` passed with
  9 tests and 203 assertions; the product-cart-checkout context proof passed with 1 test and
  58 assertions.

## Required Contract Tests Still Pending

- Full validation error shape for every mutation. Address create/update plus the core cart,
  checkout, Favorite List, product review and service-cart mutation families are covered; remaining
  secondary mutations still need the same proof.
- Browser-to-API parity for product, service, cart, checkout, tax, payment and Favorite List flows.
- Cart tax preview REST parity has focused proof; browser screenshot parity for every checkout tax
  display remains part of the broader storefront regression suite.
- Idempotent checkout retry behavior is covered for a normal product cart with payment-link
  creation. Service-cart and mixed-cart idempotency still need parity proof.
- Remaining broader context-stock matrices beyond the focused vendor/channel/warehouse proof.
- Browser screenshot proof for backend order filters beyond the current config/statistics proof.
- API benchmark matrix.
