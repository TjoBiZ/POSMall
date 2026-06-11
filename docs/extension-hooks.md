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
