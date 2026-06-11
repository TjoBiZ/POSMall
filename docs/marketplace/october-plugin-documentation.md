# POSMall - PostgreSQL eCommerce Plugin for October CMS

POSMall is a PostgreSQL-first eCommerce plugin for October CMS and Laravel. It provides the commerce backend for product catalogs, services, virtual products, cart, checkout, orders, payments, shipping, taxes, discounts, reviews, customer accounts, POS-style workflows and API-ready business automation.

- October CMS Marketplace: <https://octobercms.com/plugin/kodzero-posmall>
- Companion POSMall Theme: <https://octobercms.com/theme/kodzero-posmalltheme>
- Plugin source: <https://github.com/TjoBiZ/POSMall>
- Theme source: <https://github.com/TjoBiZ/POSMallTheme>

## Key Features

- Product catalog with categories, brands, variants, images, prices and stock.
- PostgreSQL-backed product index for filtering, sorting and scalable catalog pages.
- Product properties for filters, variant selection and item customization.
- Services, service options, virtual products and downloadable product workflows.
- Cart, checkout, orders, order states, payment logs and customer account flows.
- Shipping methods, taxes, discounts, reviews and wishlists.
- Payment provider foundation for Stripe, PayPal, Omnipay integrations and payment links.
- API-ready architecture with REST endpoints, API tokens, commerce context, vendors, channels and warehouses.
- Demo seed command for a ready product, service and virtual-product catalog.
- Companion storefront theme for customer-facing catalog, search, cart, checkout and account pages.

## Installation

Install the public Marketplace packages with Composer:

```bash
composer require kodzero/posmall-plugin kodzero/posmalltheme-theme -W
php artisan october:migrate
php artisan theme:use kodzero-posmalltheme --force
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

POSMall creates baseline commerce settings during installation, including a default USD currency and default commerce rows required for a clean store.

## Demo Catalog

For demo or staging stores, seed a ready storefront catalog:

```bash
php artisan posmall:seed-wings-of-win --force
php artisan posmall:index --force
php artisan posmall:images:optimize-catalog --profile=all
php artisan cache:clear
php artisan view:clear
```

The demo seed creates product examples, service examples, virtual-product examples, catalog properties, prices, images and related commerce data. Use it only on new demo or staging stores because `--force` replaces local POSMall catalog data.

## Recommended Theme

The recommended storefront is POSMall Theme:

<https://octobercms.com/theme/kodzero-posmalltheme>

The theme provides SEO-ready catalog, product, service, cart, checkout, payment-link, customer-account and legal pages connected to POSMall components.

## Development Source Install

Use direct GitHub `dev-main` installs only for development or unreleased commit verification:

```bash
composer config repositories.posmall '{"type":"vcs","url":"https://github.com/TjoBiZ/POSMall.git","no-api":true}'
composer config repositories.posmall-theme '{"type":"vcs","url":"https://github.com/TjoBiZ/POSMallTheme.git","no-api":true}'
composer require kodzero/posmall-plugin:dev-main kodzero/posmalltheme-theme:dev-main -W --prefer-source --no-interaction
```

For normal October CMS users, use the Marketplace Composer packages without `dev-main`.

## Keywords

October CMS eCommerce plugin, Laravel eCommerce plugin, PostgreSQL eCommerce, OctoberCMS shop, product catalog, POS, cart checkout, services catalog, virtual products, Stripe, PayPal, API commerce, GraphQL-ready commerce, POSMall.
