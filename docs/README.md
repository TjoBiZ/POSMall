# POSMall Public Documentation

This directory contains public-safe POSMall documentation that can be published with the plugin repository.

## Public Package Links

- October CMS Marketplace plugin page: <https://octobercms.com/plugin/kodzero-posmall>
- October CMS Marketplace theme page: <https://octobercms.com/theme/kodzero-posmalltheme>
- POSMall plugin source repository: <https://github.com/TjoBiZ/POSMall>
- POSMall theme source repository: <https://github.com/TjoBiZ/POSMallTheme>

## Marketplace Installation

Install the public packages through Composer:

```bash
composer require kodzero/posmall-plugin kodzero/posmalltheme-theme -W
php artisan october:migrate
php artisan theme:use kodzero-posmalltheme --force
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

Use `dev-main` and direct GitHub VCS repositories only for development or unreleased commit testing.

## REST API

- `api-v1-rest.md` - human-readable REST API guide.
- `api-v1-contract.md` - endpoint contract table and route access model.
- `openapi-v1.yaml` - OpenAPI skeleton for `/posmall/api/v1`.

The REST API documentation describes controlled external/client API support. It intentionally does not include private internal strategy, local AI-assistance notes, private GraphQL operating notes or secret credentials.

## Extension Hooks

- `extension-hooks.md` - public programming extension hooks for companion plugins, internal
  modules and custom POSMall solutions. Use these hooks to connect project-specific functionality
  to POSMall Core without adding a required dependency to the public Marketplace package.

## Taxes

- `usa-tax-auto-update.md` - USA tax auto-update behavior, cron guidance and safety notes.

## Private/Internal Documentation

Local architecture reports, advisor logs, benchmark raw data, GraphQL private operating notes and AI-assistance files are intentionally not published from this directory. Those files belong to the local development workspace and must not be committed to the public repository unless explicitly reviewed and approved for publication.
