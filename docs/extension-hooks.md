# POSMall Extension Hooks

POSMall public core exposes small neutral hooks for companion extension plugins. Starting with the
release branch that includes these hooks, projects can add their own POSMall-related functionality
from a separate installed plugin without changing the public core package.

This is a programming extension point, not a public dependency. The public plugin does not require
any private extension package. Internal products such as a POSMall PRO plugin, agency-specific
catalog rules, B2B pricing modules, warehouse/channel context, customer-segment visibility or other
custom solutions can listen to these hooks when they are installed.

The goal is to keep POSMall Core stable and Marketplace-safe while still allowing project owners
and developers to integrate their own modules around the public catalog/search/category storefront.

## Public Storefront Cache

Class:

```text
KodZero\POSMall\Classes\Http\PublicStorefrontCache
```

Events:

```php
PublicStorefrontCache::EVENT_EXTEND_ELIGIBILITY
PublicStorefrontCache::EVENT_EXTEND_KEY_PARTS
```

### Listener Registration

Register listeners in the extension plugin `register()` method, not `boot()`.

POSMall checks for a public-cache preflight hit during its own `boot()` method. OctoberCMS runs all
plugin `register()` methods before plugin `boot()` methods, so `register()` is the safe place for
cache-key listeners that must be available early.

### Eligibility Hook

The eligibility hook may disable public cache for a request:

```php
use Event;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;

Event::listen(PublicStorefrontCache::EVENT_EXTEND_ELIGIBILITY, function (bool &$eligible, $request): void {
    if ($request->headers->has('X-Custom-Catalog-Context')) {
        $eligible = false;
    }
});
```

This hook cannot re-enable cache for requests already rejected by core rules, including non-GET
requests, AJAX requests, non-cacheable paths and requests with personal cookies.

### Cache-Key Parts Hook

The key-parts hook may add deterministic dimensions to the anonymous public cache key:

```php
use Event;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;

Event::listen(PublicStorefrontCache::EVENT_EXTEND_KEY_PARTS, function (array &$parts, $request): void {
    $parts[] = 'price-list:retail';
    $parts[] = 'channel:web';
});
```

Rules for listener values:

- Push only deterministic scalar, array or serializable values.
- Prefer grouped dimensions such as price list, company group, channel or warehouse over per-user
  IDs when possible.
- Do not use `microtime()`, random values or mutable session state.
- If a listener throws, POSMall bypasses public cache for that request instead of falling back to a
  generic public key.
- If a listener varies output by request header, configure the CDN or response layer with matching
  `Vary` behavior.

## Storefront Catalog Visibility

Catalog/category/search listings expose a filter extension hook before POSMall queries the product
index:

```text
posmall.products.filter.extend
```

This hook receives:

```text
KodZero\POSMall\Components\Products $component
Illuminate\Support\Collection $filters
```

Example: hide products that are not visible in the current channel:

```php
use Event;
use KodZero\POSMall\Classes\CategoryFilter\SetFilter;

Event::listen('posmall.products.filter.extend', function ($component, $filters): void {
    $hiddenProductIds = [10, 25, 31];

    if ($hiddenProductIds !== []) {
        $filters->put('product_id', new SetFilter('product_id', $hiddenProductIds, true));
    }
});
```

Use this hook for storefront catalog visibility rules that can be expressed as POSMall index
filters. For customer-specific, channel-specific or warehouse-specific visibility, the extension
plugin must also vary or disable `PublicStorefrontCache` so anonymous HTML is not reused for the
wrong context.

This is a storefront listing hook. It does not automatically restrict unrelated direct Eloquent
queries, backend screens or custom API endpoints.

## Priceable Pricing

Product, variant, service-option and other priceable models expose one neutral price extension
hook:

```php
KodZero\POSMall\Classes\Events\PriceEvents::EXTEND_PRICE
```

Event name:

```text
posmall.priceable.extendPrice
```

This hook receives:

```text
KodZero\POSMall\Models\Price &$price
mixed $item
KodZero\POSMall\Models\Currency $currency
string $relation
?Closure $filter
```

The hook runs after POSMall has resolved the core price and existing customer-group price. A
listener may replace `$price` with another `Price` instance:

```php
use Event;
use KodZero\POSMall\Classes\Events\PriceEvents;
use KodZero\POSMall\Models\Price;
use KodZero\POSMall\Models\Product;

Event::listen(PriceEvents::EXTEND_PRICE, function (Price &$price, $item): void {
    if (!$item instanceof Product) {
        return;
    }

    $price = $price->withPrice(49.99);
});
```

Rules for pricing listeners:

- Keep `$price` as a `Price` instance.
- Check the `$item` type before applying product-only or variant-only logic.
- Do not use this hook to hide products from listings; use `posmall.products.filter.extend` for
  storefront catalog visibility.
- Keep listener work lightweight; `price()` is called in catalog, product, cart and API hot paths.
- Batch, preload or memoize private price-list rules instead of querying once per product card.
- If a listener varies price by customer, session, header, segment, channel, warehouse or price
  list, it must also extend or disable `PublicStorefrontCache`.
- Listener exceptions are not swallowed by public core. A private extension should catch only
  errors it can safely recover from; otherwise failing closed is safer than silently showing or
  charging the wrong price.
