# POSMall REST API v1

POSMall REST API v1 exposes the same commerce capabilities used by the storefront: catalog browsing, services, cart, customer addresses, checkout, order lookup, reviews, Favorite Lists and API payment links.

## Authentication

Enable the API in **Settings > POSMall API** and create tokens from the backend **POSMall API Tokens** page or with:

```bash
php artisan posmall:api-token:create integration-name --scope='*'
```

Send the token as:

```http
Authorization: Bearer posmall_xxx
```

or:

```http
X-POSMall-API-Key: posmall_xxx
```

Tokens support scopes, optional allowed origins, expiry, revocation and per-token rate limits.
The backend token form includes a permission tree that writes the same stored scopes shown below.
Advanced users can still inspect or edit the raw scope list, but POSMall rejects unknown scope
strings instead of silently storing them.

## Backend Settings

API runtime behavior is configured from **Settings > POSMall API**:

- `Enable POSMall API` must be enabled before any `/posmall/api/v1/*` route accepts tokens.
- `Default requests per minute` is used when a token does not define its own rate limit.
- `Payment link expiry minutes` controls API-generated website payment links.
- `Global allowed origins` optionally limits browser-origin requests before token scope checks run.
- `Catalog API cache seconds` controls the short catalog JSON cache. Product, price, image, rating, stock, vendor, channel and warehouse changes bump the cache version.

Tokens are managed from **POSMall API Tokens**. Token-level settings can narrow access further:

- scopes, one per line;
- optional token-level allowed origins;
- optional allowed customer IDs;
- optional allowed vendor IDs;
- optional allowed channel IDs;
- optional allowed warehouse IDs;
- optional per-token rate limit;
- expiry and revocation timestamps.

Empty allowed-ID lists mean the token is not narrowed by that dimension. Non-empty lists are
enforced for customer resources, order lookups and explicit vendor/channel/warehouse context
headers.

## Token Permission Tree

The admin permission tree is a readable UI over the stored scope list. The stored scopes remain the
runtime source of truth.

| Business area | Scope | Access | Meaning |
|---|---|---|---|
| Catalog and discovery | `catalog:read` | Read | Products, categories, brands, properties, vendors, channels, warehouses and stock. |
| Catalog and discovery | `reviews:write` | Write | Create product reviews for an allowed customer. |
| Cart and checkout | `cart:read` | Read | Read customer cart contents, totals, shipping methods and payment methods. |
| Cart and checkout | `cart:write` | Write | Add, update and remove cart items, discounts, address, shipping and payment method. |
| Cart and checkout | `checkout:write` | Write | Convert a prepared cart into an order and optionally create a website payment link. |
| Customers and orders | `customers:read` | Read | Read an allowed customer profile and addresses. |
| Customers and orders | `customers:write` | Write | Create and update customer addresses and delivery notes. |
| Customers and orders | `orders:read` | Read | Read order lists, order detail and order status for an allowed customer/context. |
| Favorite Lists | `favorites:read` | Read | Read Favorite Lists for an allowed customer. |
| Favorite Lists | `favorites:write` | Write | Create Favorite Lists and add or remove Favorite List items. |
| Trusted internal access | `*` | All | Bypasses individual scope checks. Use only for fully trusted server-side integrations. |

Context allow-lists are applied after scopes. For example, a token with `catalog:read` and allowed
vendor ID `10` can read catalog data only inside that allowed vendor context when an explicit
vendor context is requested.

Recommended production pattern:

- grant only the exact scopes needed by the integration;
- set customer/vendor/channel/warehouse allow-lists whenever the integration is not fully trusted;
- avoid `*` outside internal server-to-server maintenance tools;
- keep browser-origin restrictions narrow for browser-based integrations;
- rotate or revoke tokens that are no longer used.

This public document covers the REST API. Keep non-REST integrations disabled unless the
integration has been reviewed, scoped and tested for the specific server-to-server use case.

For the endpoint-by-endpoint contract table, see `api-v1-contract.md`.

## Commerce Context

Optional headers select the business context:

```http
X-POSMall-Vendor: default
X-POSMall-Channel: web
X-POSMall-Warehouse: main
```

If product context pivots are empty, products are available globally. If products are assigned to a vendor, channel or warehouse inventory context, catalog API responses are restricted to that context. Direct cart item creation uses the same product context rule, so a product hidden from the selected vendor/channel/warehouse cannot be added by ID.

## Endpoints

Catalog:

- `GET /posmall/api/v1/status`
- `GET /posmall/api/v1/categories`
- `GET /posmall/api/v1/brands`
- `GET /posmall/api/v1/brands/{slug}`
- `GET /posmall/api/v1/brands/{slug}/products`
- `GET /posmall/api/v1/properties`
- `GET /posmall/api/v1/products`
- `GET /posmall/api/v1/products/{slug}`
- `GET /posmall/api/v1/products/{slug}/reviews`
- `POST /posmall/api/v1/products/{slug}/reviews`

Commerce context:

- `GET /posmall/api/v1/vendors`
- `GET /posmall/api/v1/vendors/{slug}`
- `GET /posmall/api/v1/vendors/{slug}/products`
- `GET /posmall/api/v1/channels`
- `GET /posmall/api/v1/channels/{slug}`
- `GET /posmall/api/v1/warehouses`
- `GET /posmall/api/v1/warehouses/{slug}`
- `GET /posmall/api/v1/warehouses/{slug}/stock`

Services:

- `GET /posmall/api/v1/services`
- `GET /posmall/api/v1/services/{code}`
- `POST /posmall/api/v1/services/{code}/cart`

Standalone services can be global or assigned to a vendor/channel. When a request explicitly sends
`X-POSMall-Vendor` or `X-POSMall-Channel`, POSMall returns global services plus services assigned
to that context and hides services assigned to a different explicit context.

Cart and checkout:

- `GET /posmall/api/v1/cart`
- `POST /posmall/api/v1/cart/items`
- `PATCH /posmall/api/v1/cart/items/{item}`
- `DELETE /posmall/api/v1/cart/items/{item}`
- `POST /posmall/api/v1/cart/discounts`
- `GET /posmall/api/v1/cart/shipping-methods`
- `POST /posmall/api/v1/cart/shipping-method`
- `GET /posmall/api/v1/cart/payment-methods`
- `POST /posmall/api/v1/cart/payment-method`
- `GET /posmall/api/v1/cart/tax-preview`
- `POST /posmall/api/v1/cart/address`
- `POST /posmall/api/v1/checkout/orders`

`POST /posmall/api/v1/cart/items` checks both the customer allow-list and the selected commerce
context. The endpoint returns `404` when the product is published but hidden from the requested
vendor/channel/warehouse context.
- `GET /posmall/api/v1/orders`
- `GET /posmall/api/v1/orders/{hash}`
- `GET /posmall/api/v1/orders/{hash}/status`

Customers:

- `GET /posmall/api/v1/customers/{customer}`
- `GET /posmall/api/v1/customers/{customer}/addresses`
- `GET /posmall/api/v1/customers/{customer}/orders`
- `POST /posmall/api/v1/customers/{customer}/addresses`
- `PATCH /posmall/api/v1/customers/{customer}/addresses/{address}`

Favorite Lists:

- `GET /posmall/api/v1/favorite-lists`
- `POST /posmall/api/v1/favorite-lists`
- `POST /posmall/api/v1/favorite-lists/{list}/items`
- `DELETE /posmall/api/v1/favorite-lists/{list}/items/{item}`

## Request Examples

List products:

```http
GET /posmall/api/v1/products?category=scarves&sort=price_asc&per_page=24&page=1
Authorization: Bearer posmall_xxx
```

List vendor products:

```http
GET /posmall/api/v1/vendors/default/products?per_page=24&page=1
Authorization: Bearer posmall_xxx
X-POSMall-Channel: web
X-POSMall-Warehouse: main
```

List warehouse stock:

```http
GET /posmall/api/v1/warehouses/main/stock?per_page=50&page=1
Authorization: Bearer posmall_xxx
```

Read filter metadata:

```http
GET /posmall/api/v1/properties?category=scarves&with_values=1
Authorization: Bearer posmall_xxx
```

Typical catalog/discovery read responses:

```json
{
  "ok": true,
  "data": {
    "items": [
      {"id": 1, "name": "Scarves", "slug": "scarves"},
      {"id": 2, "name": "Gift Cards", "slug": "gift-cards"}
    ]
  }
}
```

```json
{
  "ok": true,
  "data": {
    "items": [
      {"id": 1, "name": "WingsOfWin", "slug": "wingsofwin"}
    ],
    "pagination": {"page": 1, "per_page": 24, "total": 1}
  }
}
```

Vendor, channel and warehouse detail endpoints return the selected context object. Product-list
and stock endpoints under those context objects return paginated product or stock rows:

```json
{
  "ok": true,
  "data": {
    "vendor": {"id": 1, "name": "Default Vendor", "slug": "default"},
    "items": [
      {"id": "product-123", "name": "Purple Bird Wing Shawl", "slug": "purple-bird-wing-shawl"}
    ],
    "pagination": {"page": 1, "per_page": 24, "total": 1}
  }
}
```

Add a product to cart:

```json
{
  "customer_id": 1,
  "product_id": "product-1",
  "variant_id": null,
  "quantity": 2,
  "service_option_ids": []
}
```

Set cart address:

```json
{
  "customer_id": 1,
  "address_id": 18,
  "type": "both"
}
```

Preview cart taxes:

```http
GET /posmall/api/v1/cart/tax-preview?customer_id=1
Authorization: Bearer posmall_xxx
```

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

Create a customer address:

```json
{
  "company": "",
  "firstname": "Jane",
  "lastname": "Customer",
  "lines": "100 Market Street",
  "zip": "94105",
  "city": "San Francisco",
  "country_id": 4,
  "state_id": 722,
  "phone": "+14155550100",
  "delivery_notes": "Gate code, front desk, delivery instructions",
  "default_shipping": true,
  "default_billing": true
}
```

Create an order and payment link:

```json
{
  "customer_id": 1,
  "idempotency_key": "external-order-123",
  "customer_notes": "Customer requested delivery before noon.",
  "create_payment_link": true
}
```

List a customer's orders:

```http
GET /posmall/api/v1/customers/1/orders?per_page=20&page=1
Authorization: Bearer posmall_xxx
X-POSMall-Vendor: default
```

Add a service to cart:

```json
{
  "customer_id": 1,
  "service_option_ids": [1]
}
```

Create a review:

```json
{
  "customer_id": 1,
  "rating": 5,
  "title": "Great product",
  "description": "The order arrived correctly.",
  "pros": ["fast delivery"],
  "cons": []
}
```

Create a Favorite List:

```json
{
  "customer_id": 1,
  "name": "Work order shortlist"
}
```

Add a Favorite List item:

```json
{
  "customer_id": 1,
  "product_id": "product-1",
  "variant_id": null,
  "quantity": 1
}
```

## Response Shape

All API responses use:

```json
{
  "ok": true,
  "data": {}
}
```

Errors use:

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "The request could not be validated."
  }
}
```

## API Payment Links

`POST /posmall/api/v1/checkout/orders` can create a website payment link for the created order:

```json
{
  "customer_id": 1,
  "idempotency_key": "external-order-123",
  "create_payment_link": true
}
```

The API returns an order payload and a payment link. POSMall stores only a hash of the link token. A repeated request with the same idempotency key returns the same order and does not invalidate an already active payment link.

## Idempotency

Checkout idempotency is scoped by API token, customer, vendor, channel and warehouse. Use a stable `idempotency_key` per external order creation attempt.

## Security Notes

- Use HTTPS.
- Use narrow scopes for production integrations.
- Use allowed customer/vendor/channel/warehouse ID lists for partner, marketplace and limited
  integrations.
- Rotate tokens from the backend API Tokens page.
- Keep payment links short-lived where possible.
- Do not expose API tokens in browser code unless origin restrictions and scopes are intentionally configured.
