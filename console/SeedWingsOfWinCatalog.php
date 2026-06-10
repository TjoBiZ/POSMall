<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\CustomFieldOption;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\ImageSet;
use KodZero\POSMall\Models\Price;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ProductFile;
use KodZero\POSMall\Models\ProductPrice;
use KodZero\POSMall\Models\Property;
use KodZero\POSMall\Models\PropertyGroup;
use KodZero\POSMall\Models\PropertyValue;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\ServiceOption;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UniquePropertyValue;
use KodZero\POSMall\Models\Variant;
use KodZero\POSMall\Classes\Security\BackendAdminSafety;
use System\Models\File as SystemFile;

class SeedWingsOfWinCatalog extends Command
{
    private const ROOT = 'plugins/kodzero/posmall/updates/seeders/demo';

    protected $signature = 'posmall:seed-wings-of-win
        {--force : Confirm destructive catalog cleanup}
        {--without-index : Skip rebuilding kodzero_posmall_index after seeding}';

    protected $description = 'Replace the local catalog with the curated WingsOfWin WOW seed dataset.';

    private Currency $currency;

    public function handle(): int
    {
        if (! $this->isSafeEnvironment()) {
            $this->error('This seed command is local/dev/testing only.');

            return 1;
        }

        if (! $this->option('force')) {
            $this->error('This command clears the product catalog. Re-run with --force after taking a backup.');

            return 1;
        }

        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:seed-wings-of-win before catalog cleanup');

        $this->currency = Currency::where('code', 'USD')->first() ?? Currency::where('is_enabled', true)->firstOrFail();
        $this->configureDemoStore();

        $this->ensureAssets();
        $this->clearCatalog();

        $brand = $this->syncBrand();
        $group = $this->syncPropertyGroup();
        $categories = $this->syncCategories($group);
        $properties = $this->syncProperties($group);
        $services = $this->syncServices();
        $washingtonTax = $this->syncWashingtonTax();
        $this->syncDemoDiscount();

        $products = $this->syncImportedProducts($categories, $properties);
        $this->syncGiftProducts($categories['gift']);
        $this->syncDigitalTemplates($categories['digital'], $services['print_consultation']);
        $this->syncPackagingProducts($categories['packaging'], $services['gift_delivery']);
        $this->syncStandaloneServiceCarriers($services, $categories['services']);
        $this->assignProductsToBrand($brand);

        $scarfProducts = collect($products)->filter(fn (Product $product) => ! $product->is_virtual);
        $this->attachServicesToProducts($scarfProducts, [
            $services['diy_assistance'],
            $services['gift_delivery'],
        ]);
        $this->attachLogoCustomField($scarfProducts);
        $this->attachWashingtonTax($washingtonTax);

        foreach (Category::all() as $category) {
            UniquePropertyValue::resetForCategory($category);
        }

        if (! $this->option('without-index')) {
            $this->callSilent('posmall:index', ['--force' => true]);
        }

        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:seed-wings-of-win after catalog seed');

        $this->info('WingsOfWin WOW catalog seed completed.');

        return 0;
    }

    private function isSafeEnvironment(): bool
    {
        return app()->environment(['local', 'dev', 'development', 'testing']);
    }

    private function clearCatalog(): void
    {
        SystemFile::where(function ($query) {
            $query
                ->whereIn('attachment_type', [
                    ImageSet::MORPH_KEY,
                    Brand::class,
                    Service::class,
                    ProductFile::class,
                ]);

            foreach (['Brand', 'Service', 'ProductFile'] as $model) {
                $query->orWhere('attachment_type', 'like', '%Mall%Models%' . $model);
            }
        })
            ->get()
            ->each
            ->delete();

        if (DB::getSchemaBuilder()->hasTable('kodzero_posmall_prices')) {
            DB::table('kodzero_posmall_prices')
                ->whereIn('priceable_type', [
            CustomField::MORPH_KEY,
            CustomFieldOption::MORPH_KEY,
            Product::MORPH_KEY,
            ServiceOption::MORPH_KEY,
            Variant::MORPH_KEY,
                ])
                ->delete();
        }

        $tables = [
            'kodzero_posmall_cart_custom_field_value',
            'kodzero_posmall_cart_discount',
            'kodzero_posmall_cart_product_service_option',
            'kodzero_posmall_cart_products',
            'kodzero_posmall_wishlist_items',
            'kodzero_posmall_wishlists',
            'kodzero_posmall_product_accessory',
            'kodzero_posmall_product_tax',
            'kodzero_posmall_service_tax',
            'kodzero_posmall_shipping_method_tax',
            'kodzero_posmall_payment_method_tax',
            'kodzero_posmall_country_tax',
            'kodzero_posmall_state_tax',
            'kodzero_posmall_taxes',
            'kodzero_posmall_product_prices',
            'kodzero_posmall_property_values',
            'kodzero_posmall_unique_property_values',
            'kodzero_posmall_product_variants',
            'kodzero_posmall_product_file_variant',
            'kodzero_posmall_product_files',
            'kodzero_posmall_product_custom_field',
            'kodzero_posmall_custom_field_options',
            'kodzero_posmall_custom_fields',
            'kodzero_posmall_product_service',
            'kodzero_posmall_service_options',
            'kodzero_posmall_services',
            'kodzero_posmall_category_product',
            'kodzero_posmall_category_property_group',
            'kodzero_posmall_property_property_group',
            'kodzero_posmall_properties',
            'kodzero_posmall_property_groups',
            'kodzero_posmall_image_sets',
            'kodzero_posmall_index',
            'kodzero_posmall_products',
            'kodzero_posmall_discounts',
            'kodzero_posmall_brands',
            'kodzero_posmall_categories',
        ];

        $existing = collect($tables)
            ->filter(fn (string $table) => DB::getSchemaBuilder()->hasTable($table))
            ->map(fn (string $table) => '"' . str_replace('"', '""', $table) . '"')
            ->implode(', ');

        if ($existing === '') {
            return;
        }

        DB::statement('TRUNCATE ' . $existing . ' RESTART IDENTITY');

        (new Category())->purgeCache();
    }

    private function syncBrand(): Brand
    {
        $brand = Brand::create([
            'name' => 'wingsofwin',
            'slug' => 'wingsofwin',
            'description' => '<p>wingsofwin is the source brand for the curated demo catalog of wing scarves, gift products, printable templates, packaging examples and related services. The brand site is wingsofwin.com.</p>',
            'website' => 'https://wingsofwin.com',
            'meta_title' => 'wingsofwin',
            'meta_keywords' => 'wingsofwin, wing scarves, scarf templates, gift packaging',
            'meta_description' => 'wingsofwin demo brand for wing scarves, gift credits, printable templates and related catalog services.',
            'sort_order' => 10,
        ]);

        if (is_file($this->logoImagePath())) {
            $brand->logo()->add($brand->logo()->make()->fromFile($this->logoImagePath()));
        }

        return $brand;
    }

    private function assignProductsToBrand(Brand $brand): void
    {
        Product::query()->update(['brand_id' => $brand->id]);
    }

    private function syncDemoDiscount(): Discount
    {
        return Discount::create([
            'name' => 'WingsOfWin 30% Demo Discount',
            'type' => 'rate',
            'trigger' => 'code',
            'rate' => 30,
            'code' => 'WOW30',
            'number_of_usages' => 0,
            'max_number_of_usages' => null,
        ]);
    }

    private function syncPropertyGroup(): PropertyGroup
    {
        return PropertyGroup::create([
            'name' => 'WingsOfWin Attributes',
            'display_name' => 'Attributes',
            'slug' => 'wow-attributes',
            'description' => 'Curated WingsOfWin scarf, gift and printable template attributes.',
        ]);
    }

    /**
     * @return array<string, Category>
     */
    private function syncCategories(PropertyGroup $group): array
    {
        $definitions = [
            'scarves' => ['Scarves', 'scarves', null, 'Winged scarves, shawls and wraps.'],
            'silk' => ['Silk Scarves', 'silk-scarves', 'scarves', 'Natural silk scarf designs.'],
            'cotton' => ['Cotton Scarves', 'cotton-scarves', 'scarves', 'Natural cotton scarf designs.'],
            'wool' => ['Woolen Scarves', 'woolen-scarves', 'scarves', 'Warm wool and silk fiber wraps.'],
            'family' => ['Family Look', 'family-look', null, 'Matching scarf sets for family gifts.'],
            'bridesmaids' => ['Bridesmaid Scarves', 'bridesmaids-scarves', null, 'Scarves and sets for bridal parties.'],
            'gift' => ['Gift', 'gift', null, 'Virtual gift credits and greeting products.'],
            'digital' => ['Digital Scarf Templates', 'digital-scarf-templates', null, 'Printable vector scarf templates for personal print runs.'],
            'packaging' => ['Gift Packaging', 'gift-packaging', null, 'Physical and downloadable gift box packaging.'],
            'services' => ['Services', 'services', null, 'Standalone service carrier products used by checkout.'],
        ];

        $categories = [];
        foreach ($definitions as $key => [$name, $slug, $parentKey, $description]) {
            $category = Category::create([
                'name' => $name,
                'slug' => $slug,
                'code' => 'WOW-CAT-' . strtoupper(str_replace('-', '_', $slug)),
                'description_short' => $description,
                'description' => '<p>' . e($description) . '</p>',
                'inherit_property_groups' => true,
                'inherit_review_categories' => true,
                'sort_order' => count($categories) + 10,
            ]);

            if ($parentKey && isset($categories[$parentKey])) {
                $category->parent_id = $categories[$parentKey]->id;
                $category->save();
            }

            $category->property_groups()->syncWithoutDetaching([$group->id => ['relation_sort_order' => 0]]);
            $categories[$key] = $category;
        }

        return $categories;
    }

    /**
     * @return array<string, Property>
     */
    private function syncProperties(PropertyGroup $group): array
    {
        $definitions = [
            'Color' => 'wow-color',
            'Additional color' => 'wow-additional-color',
            'Fabric' => 'wow-fabric',
            'Length' => 'wow-length',
            'Width' => 'wow-width',
            'Quantity' => 'wow-quantity',
            'Gift amount' => 'wow-gift-amount',
            'License' => 'wow-license',
            'Delivery format' => 'wow-delivery-format',
        ];

        $properties = [];
        $sort = 0;
        foreach ($definitions as $name => $slug) {
            $property = Property::create([
                'name' => $name,
                'slug' => $slug,
                'type' => 'dropdown',
                'unit' => '',
                'options' => [],
            ]);
            $group->properties()->attach($property->id, [
                'use_for_variants' => in_array($slug, ['wow-fabric', 'wow-quantity', 'wow-gift-amount'], true),
                'filter_type' => 'set',
                'sort_order' => $sort++,
            ]);
            $properties[$name] = $property;
        }

        return $properties;
    }

    /**
     * @return array<string, Service>
     */
    private function syncServices(): array
    {
        return [
            'diy_assistance' => $this->createService('DIY Production Help', 'Get practical help producing the scarf yourself.', [
                ['Find a textile printing company', 3500],
                ['Order cotton fabric for printing', 1800],
                ['Order silk fabric for printing', 3200],
                ['Coordinate one small print batch', 5500],
                ['Take finished item to post office', 2400],
            ], 'diy-production-help'),
            'gift_delivery' => $this->createService('Personal Gift Delivery', 'Add personal gift delivery and presentation help.', [
                ['Deliver to the recipient door', 2800],
                ['Present personally and congratulate', 6500],
                ['Gift wrapping service', 1900],
            ], 'personal-gift-delivery'),
            'print_consultation' => $this->createService('Print Material Consultation', 'Learn how to choose fabric and printing settings for a vector scarf template.', [
                ['Material selection consultation', 2900],
                ['Typography and layout review', 2200],
                ['Color adjustment review', 3400],
            ], 'print-material-consultation'),
        ];
    }

    private function createService(string $name, string $description, array $options, string $slug): Service
    {
        $service = Service::create([
            'name' => $name,
            'description' => '<p>' . e($description) . '</p>',
            'meta_title' => $name,
            'meta_description' => $description,
            'gallery_autoplay_seconds' => 3.5,
        ]);
        $service->code = $slug;
        $service->save();
        $service->images()->add($service->images()->make()->fromFile($this->serviceImagePath()));

        foreach ($options as $index => [$optionName, $price]) {
            $option = ServiceOption::create([
                'service_id' => $service->id,
                'name' => $optionName,
                'description' => '<p>' . e($optionName) . '</p>',
                'sort_order' => ($index + 1) * 10,
            ]);
            $this->syncMorphPrice($option, $price);
        }

        return $service;
    }

    /**
     * @return array<int, Product>
     */
    private function syncImportedProducts(array $categories, array $properties): array
    {
        $rows = $this->csv('products.csv');
        $productRows = [];
        $variationRows = [];

        foreach ($rows as $row) {
            if (($row['post_type'] ?? '') === 'product') {
                $productRows[$row['source_post_id']] = $row;
            }
            if (($row['post_type'] ?? '') === 'product_variation') {
                $variationRows[$row['source_parent_id']][] = $row;
            }
        }

        $categoryBySlug = collect($categories)->keyBy('slug');
        $created = [];

        foreach ($productRows as $sourceId => $row) {
            $variations = $variationRows[$sourceId] ?? [];
            $price = $variations
                ? collect($variations)->map(fn ($variant) => $this->normalizedImportedPrice($variant, $row))->filter()->min()
                : $this->normalizedImportedPrice($row);
            $price = $price ?: 5900;

            $product = Product::create([
                'user_defined_id' => 'WOW-' . str_pad((string)$sourceId, 5, '0', STR_PAD_LEFT),
                'name' => $this->displayTitle($row),
                'slug' => $this->displaySlug($row),
                'description_short' => $this->shortText($this->descriptionHtml($row)),
                'description' => $this->descriptionHtml($row),
                'meta_title' => $row['seo_title'] ?: $this->displayTitle($row),
                'meta_description' => $row['seo_description'] ?: $this->shortText($this->descriptionHtml($row)),
                'inventory_management_method' => $variations ? 'variant' : 'single',
                'quantity_default' => 1,
                'quantity_min' => 1,
                'quantity_max' => 12,
                'stock' => $variations ? 0 : 10,
                'allow_out_of_stock_purchases' => false,
                'stackable' => true,
                'is_virtual' => false,
                'shippable' => true,
                'price_includes_tax' => false,
                'published' => ($row['status'] ?? '') === 'publish',
                'weight' => 200,
                'length' => 200,
                'width' => 70,
                'height' => 2,
                'additional_properties' => [
                    ['name' => 'Catalog ID', 'value' => 'WOW-' . str_pad((string)$sourceId, 5, '0', STR_PAD_LEFT)],
                    ['name' => 'Original source', 'value' => 'WingsOfWin curated import'],
                ],
                'embeds' => $this->videoEmbeds($row),
            ]);
            $this->syncProductPrice($product, $price);
            $this->attachCategoriesFromRow($product, $row, $categoryBySlug, $categories['scarves']);
            $this->syncPropertyValues($product, null, $row, $properties);
            $this->attachProductImages($product, $row, 'WOW product images');

            foreach ($variations as $variantIndex => $variationRow) {
                $variant = Variant::create([
                    'product_id' => $product->id,
                    'user_defined_id' => 'WOW-VAR-' . str_pad((string)$variationRow['source_post_id'], 5, '0', STR_PAD_LEFT),
                    'name' => $variationRow['title'] ?: $product->name . ' option',
                    'stock' => 8,
                    'published' => ($variationRow['status'] ?? '') === 'publish',
                    'allow_out_of_stock_purchases' => false,
                    'description_short' => $this->shortText($variationRow['short_description'] ?: $product->description_short),
                    'description' => $this->cleanDescription($variationRow['html_description'] ?: $product->description),
                    'weight' => 200,
                    'length' => 200,
                    'width' => 70,
                    'height' => 2,
                    'sort_order' => ($variantIndex + 1) * 10,
                ]);
                $this->syncProductPrice($product, $this->normalizedImportedPrice($variationRow, $row) ?: $price, $variant);
                $this->syncPropertyValues($product, $variant, $variationRow, $properties);
                $this->attachVariantImages($product, $variant, $variationRow, $row);
            }

            $created[] = $product;
        }

        return $created;
    }

    private function syncGiftProducts(Category $category): void
    {
        $product = Product::create([
            'user_defined_id' => 'WOW-GIFT',
            'name' => 'WingsOfWin Gift Credit',
            'slug' => 'wings-of-win-gift-credit',
            'description_short' => 'Virtual gift credit for a WingsOfWin order.',
            'description' => '<p>Choose a virtual gift credit amount. The selected amount is the product price: $10 costs $10, $30 costs $30, $50 costs $50, and $100 costs $100.</p>',
            'meta_title' => 'WingsOfWin Gift Credit',
            'meta_description' => 'Virtual WingsOfWin gift credit with prices that match each selected amount.',
            'inventory_management_method' => 'variant',
            'quantity_default' => 1,
            'quantity_min' => 1,
            'quantity_max' => 1,
            'stock' => 0,
            'allow_out_of_stock_purchases' => true,
            'stackable' => false,
            'is_virtual' => true,
            'shippable' => false,
            'price_includes_tax' => false,
            'published' => true,
            'file_max_download_count' => 3,
            'file_session_required' => true,
            'group_by_property_id' => $this->propertyBySlug('wow-gift-amount')?->id,
            'additional_properties' => [
                ['name' => 'Delivery format', 'value' => 'Virtual credit'],
                ['name' => 'Available amounts', 'value' => '$10, $30, $50, $100'],
            ],
        ]);
        $this->syncProductPrice($product, 1000);
        $product->categories()->attach($category->id, ['sort_order' => 10]);
        $this->attachSingleImage($product, $this->logoImagePath(), 'Gift credit logo');
        $this->syncPropertyValue($product, null, 'Delivery format', 'Virtual credit', $this->propertyBySlug('wow-delivery-format'));

        foreach ([10, 30, 50, 100] as $amount) {
            $variant = Variant::create([
                'product_id' => $product->id,
                'user_defined_id' => 'WOW-GIFT-' . $amount,
                'name' => '$' . $amount . ' gift credit',
                'stock' => 999,
                'published' => true,
                'allow_out_of_stock_purchases' => true,
                'description_short' => 'Virtual gift credit that costs $' . $amount . ' and gives $' . $amount . ' in store credit.',
                'description' => '<p>This virtual gift credit costs $' . $amount . ' and gives $' . $amount . ' in WingsOfWin store credit.</p>',
                'sort_order' => $amount,
            ]);
            $this->syncProductPrice($product, $amount * 100, $variant);
            $this->syncPropertyValue($product, $variant, 'Gift amount', '$' . $amount, $this->propertyBySlug('wow-gift-amount'));
            $this->attachDownload($product, $this->downloadPath('gift-credit-' . $amount . '.txt'), '$' . $amount . ' gift credit instructions', $variant);
        }
    }

    private function syncWashingtonTax(): Tax
    {
        $tax = Tax::create([
            'name' => 'Washington Sales Tax - Seattle demo',
            'percentage' => 10.35,
            'is_default' => false,
            'is_enabled' => true,
        ]);

        $countryId = DB::table('rainlab_location_countries')
            ->where('code', 'US')
            ->value('id');
        $stateId = DB::table('rainlab_location_states')
            ->where('country_id', $countryId)
            ->where('code', 'WA')
            ->value('id');

        if ($countryId) {
            $tax->countries()->sync([$countryId]);
        }
        if ($stateId) {
            $tax->states()->sync([$stateId]);
        }

        return $tax;
    }

    private function attachWashingtonTax(Tax $tax): void
    {
        Product::where('user_defined_id', '!=', 'WOW-GIFT')
            ->get()
            ->each(fn (Product $product) => $product->taxes()->syncWithoutDetaching([$tax->id]));

        Service::all()
            ->each(fn (Service $service) => $service->taxes()->syncWithoutDetaching([$tax->id]));

        ShippingMethod::all()
            ->each(fn (ShippingMethod $method) => $method->taxes()->syncWithoutDetaching([$tax->id]));
    }

    private function syncDigitalTemplates(Category $category, Service $consultation): void
    {
        $files = array_slice(glob(base_path(self::ROOT . '/sources/vector/*.cdr')), 0, 6);
        foreach ($files as $index => $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $price = 3900 + ($index * 500);
            $product = Product::create([
                'user_defined_id' => 'WOW-VECTOR-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name)),
                'name' => $this->headline($name) . ' Vector Scarf Template',
                'slug' => $this->slug($name . ' vector scarf template'),
                'description_short' => 'Downloadable CDR scarf template for personal printing.',
                'description' => '<p>Downloadable CorelDRAW CDR scarf template for personal print use at a textile print company. Resale, sublicensing or redistribution to third parties is not allowed, even after modification.</p>',
                'meta_title' => $this->headline($name) . ' Vector Scarf Template',
                'meta_description' => 'Downloadable CDR scarf template for personal print use.',
                'inventory_management_method' => 'single',
                'quantity_default' => 1,
                'quantity_min' => 1,
                'quantity_max' => 1,
                'stock' => 999,
                'allow_out_of_stock_purchases' => true,
                'stackable' => false,
                'is_virtual' => true,
                'shippable' => false,
                'price_includes_tax' => false,
                'published' => true,
                'file_max_download_count' => 5,
                'file_session_required' => true,
                'additional_properties' => [
                    ['name' => 'License', 'value' => 'Personal print use only; no resale or redistribution.'],
                    ['name' => 'Format', 'value' => 'CDR vector source file.'],
                ],
            ]);
            $this->syncProductPrice($product, $price);
            $product->categories()->attach($category->id, ['sort_order' => $index + 1]);
            $this->attachSingleImage($product, $this->logoImagePath(), 'Vector template logo');
            $this->attachDownload($product, $file, $this->headline($name) . ' CDR template');
            $product->services()->syncWithoutDetaching([$consultation->id => ['required' => false]]);
            $this->attachTemplateCustomFields($product);
            $this->syncPropertyValue($product, null, 'License', 'Personal print use', $this->propertyBySlug('wow-license'));
            $this->syncPropertyValue($product, null, 'Delivery format', 'CDR download', $this->propertyBySlug('wow-delivery-format'));
        }
    }

    private function syncPackagingProducts(Category $category, Service $giftDelivery): void
    {
        $items = [
            ['WOW-BOX-POPUP', 'Popup Gift Box', false, 3400, 'PrintBoxPopupCube.jpg', null],
            ['WOW-BOX-POPUP-DIGITAL', 'Popup Gift Box Vector Download', true, 2400, 'PrintBoxPopupCube.jpg', 'PrintBoxPopupCube.cdr'],
            ['WOW-GIFT-FOLDER', 'WingsOfWin Gift Folder', false, 1800, 'BoxWingsOfWin.jpg', null],
            ['WOW-GIFT-FOLDER-DIGITAL', 'WingsOfWin Gift Folder Vector Download', true, 1600, 'BoxWingsOfWin.com.jpg', '05-061-wingsofwin.com.cdr'],
        ];

        foreach ($items as $index => [$sku, $name, $virtual, $price, $image, $download]) {
            $product = Product::create([
                'user_defined_id' => $sku,
                'name' => $name,
                'slug' => $this->slug($name),
                'description_short' => $virtual ? 'Downloadable packaging vector source.' : 'Physical gift packaging for a scarf order.',
                'description' => '<p>' . e($virtual ? 'Download the vector source to prepare your own WingsOfWin gift packaging.' : 'Physical gift packaging with a popup box/folder presentation for a scarf gift.') . '</p>',
                'inventory_management_method' => 'single',
                'quantity_default' => 1,
                'quantity_min' => 1,
                'quantity_max' => $virtual ? 1 : 10,
                'stock' => $virtual ? 999 : 25,
                'allow_out_of_stock_purchases' => $virtual,
                'stackable' => ! $virtual,
                'is_virtual' => $virtual,
                'shippable' => ! $virtual,
                'price_includes_tax' => false,
                'published' => true,
                'file_max_download_count' => $virtual ? 5 : null,
                'file_session_required' => $virtual,
            ]);
            $this->syncProductPrice($product, $price);
            $product->categories()->attach($category->id, ['sort_order' => $index + 1]);
            $this->attachSingleImage($product, $this->sourcePath('packaging/' . $image), 'Packaging image');
            if ($download) {
                $this->attachDownload($product, $this->sourcePath('packaging/' . $download), $name . ' source file');
            }
            $product->services()->syncWithoutDetaching([$giftDelivery->id => ['required' => false]]);
            $this->attachPackagingCustomField($product);
        }
    }

    private function configureDemoStore(): void
    {
        DB::table('kodzero_posmall_currencies')->update(['is_default' => false]);
        DB::table('kodzero_posmall_currencies')
            ->where('id', $this->currency->id)
            ->update([
                'is_default' => true,
                'is_enabled' => true,
            ]);
        $this->currency->refresh();
        Cache::forget(Currency::CURRENCIES_CACHE_KEY);

        $settings = DB::table('system_settings')
            ->where('item', GeneralSettings::SETTINGS_CODE)
            ->value('value');
        $settings = is_string($settings) ? json_decode($settings, true) : [];
        $settings = is_array($settings) ? $settings : [];

        GeneralSettings::set(array_merge($settings, [
            'admin_email' => 'admin@example.com',
            'order_notification_email' => 'admin@example.com',
            'product_page' => 'posmall-product',
            'category_page' => 'posmall-category',
            'address_page' => 'posmall-address',
            'checkout_page' => 'posmall-checkout',
            'account_page' => 'posmall-account',
            'cart_page' => 'posmall-cart',
            'index_driver' => GeneralSettings::INDEX_DRIVER_DATABASE,
            'use_state' => true,
            'send_order_notification_to_customer' => array_key_exists('send_order_notification_to_customer', $settings)
                ? $settings['send_order_notification_to_customer']
                : true,
        ]));
    }

    private function syncStandaloneServiceCarriers(array $services, Category $category): void
    {
        $sortOrder = 0;

        foreach ($services as $service) {
            $product = Product::create([
                'user_defined_id' => \KodZero\POSMall\Components\Services::carrierSku($service),
                'name' => $service->name . ' Service',
                'slug' => $this->slug('service carrier ' . $service->code),
                'description_short' => 'Hidden checkout carrier for standalone service options.',
                'description' => '<p>Hidden checkout carrier used to price standalone service options.</p>',
                'inventory_management_method' => 'single',
                'quantity_default' => 1,
                'quantity_min' => 1,
                'quantity_max' => 1,
                'stock' => 999,
                'allow_out_of_stock_purchases' => true,
                'stackable' => false,
                'is_virtual' => true,
                'shippable' => false,
                'price_includes_tax' => false,
                'published' => true,
            ]);

            $this->syncProductPrice($product, 0);
            $product->categories()->attach($category->id, ['sort_order' => ++$sortOrder]);
            $this->attachSingleImage($product, $this->logoImagePath(), 'Service carrier logo');
        }
    }

    private function attachLogoCustomField($products): void
    {
        $field = CustomField::create([
            'name' => 'Add your logo in the scarf corner',
            'type' => 'textarea',
            'required' => false,
        ]);
        $this->syncMorphPrice($field, 2200);
        $field->products()->sync($products->pluck('id')->all());
    }

    private function attachTemplateCustomFields(Product $product): void
    {
        foreach ([
            ['Change sketch shape', 2800],
            ['Change colors', 2400],
            ['Change logo', 2600],
        ] as [$name, $price]) {
            $field = CustomField::create(['name' => $name, 'type' => 'textarea', 'required' => false]);
            $this->syncMorphPrice($field, $price);
            $field->products()->attach($product->id);
        }
    }

    private function attachPackagingCustomField(Product $product): void
    {
        $field = CustomField::create([
            'name' => 'Change box text to your message',
            'type' => 'textarea',
            'required' => false,
        ]);
        $this->syncMorphPrice($field, 1500);
        $field->products()->attach($product->id);
    }

    private function attachServicesToProducts($products, array $services): void
    {
        foreach ($products as $product) {
            foreach ($services as $service) {
                $product->services()->syncWithoutDetaching([$service->id => ['required' => false]]);
            }
        }
    }

    private function attachCategoriesFromRow(Product $product, array $row, $categoryBySlug, Category $fallback): void
    {
        $ids = [];
        foreach (explode('|', $row['category_slugs'] ?? '') as $slug) {
            $slug = trim($slug);
            $mapped = match ($slug) {
                'childrens-scarves' => null,
                default => $categoryBySlug->get($slug),
            };
            if ($mapped) {
                $ids[$mapped->id] = ['sort_order' => 0];
            }
        }
        if (! $ids) {
            $ids[$fallback->id] = ['sort_order' => 0];
        }
        $product->categories()->sync($ids);
    }

    private function syncPropertyValues(Product $product, ?Variant $variant, array $row, array $properties): void
    {
        foreach ($this->decoded($row['attributes_flat_json'] ?? '{}') as $label => $values) {
            if (! isset($properties[$label])) {
                continue;
            }
            foreach ((array)$values as $value) {
                $this->syncPropertyValue($product, $variant, $label, (string)$value, $properties[$label]);
            }
        }

        if (! $variant && $this->isWoolScarfRow($row)) {
            $this->syncPropertyValue($product, null, 'Fabric', 'Natural wool fiber', $properties['Fabric'] ?? null);
        }
    }

    private function syncPropertyValue(Product $product, ?Variant $variant, string $label, string $value, ?Property $property): void
    {
        if (! $property || trim($value) === '') {
            return;
        }

        PropertyValue::create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'property_id' => $property->id,
            'value' => $value,
        ]);
    }

    private function propertyBySlug(string $slug): ?Property
    {
        return Property::where('slug', $slug)->first();
    }

    private function syncProductPrice(Product $product, int $price, ?Variant $variant = null): void
    {
        ProductPrice::create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'currency_id' => $this->currency->id,
            'price' => $this->modelPriceValue($price),
        ]);
    }

    private function syncMorphPrice($model, int $price): void
    {
        Price::create([
            'currency_id' => $this->currency->id,
            'priceable_id' => $model->id,
            'priceable_type' => $model::MORPH_KEY,
            'price' => $this->modelPriceValue($price),
        ]);
    }

    private function modelPriceValue(int $cents): float
    {
        return $cents / 100;
    }

    private function attachProductImages(Product $product, array $row, string $name): void
    {
        $paths = array_filter(array_map('trim', explode('|', $row['copied_media_paths'] ?? '')));
        $attached = false;
        $missing = [];

        foreach ($paths as $relative) {
            $path = base_path(self::ROOT . '/images/' . preg_replace('~^media/~', '', $relative));
            if (is_file($path)) {
                $attached = $this->attachSingleImage($product, $path, $name) || $attached;
            } else {
                $missing[] = $relative;
            }
        }

        if ($missing) {
            throw new \RuntimeException(sprintf(
                'WingsOfWin seed image asset(s) missing for product "%s": %s',
                $product->slug,
                implode(', ', $missing)
            ));
        }

        if ($paths && ! $attached) {
            throw new \RuntimeException(sprintf(
                'WingsOfWin seed could not attach product image assets for "%s".',
                $product->slug
            ));
        }

        if (!$paths) {
            if ($this->attachSingleImage($product, $this->logoImagePath(), $name)) {
                $attached = true;
            }
        }
    }

    private function attachVariantImages(Product $product, Variant $variant, array $variantRow, array $productRow): void
    {
        $paths = array_filter(array_map('trim', explode('|', $variantRow['copied_media_paths'] ?? '')));
        if (! $paths) {
            $paths = array_filter(array_map('trim', explode('|', $productRow['copied_media_paths'] ?? '')));
        }

        if (! $paths) {
            return;
        }

        $set = ImageSet::create([
            'product_id' => $product->id,
            'name' => $variant->name . ' images',
            'is_main_set' => false,
        ]);

        $attached = false;
        $missing = [];

        foreach ($paths as $relative) {
            $path = base_path(self::ROOT . '/images/' . preg_replace('~^media/~', '', $relative));
            if (is_file($path)) {
                $set->images()->add($set->images()->make()->fromFile($path));
                $attached = true;
            } else {
                $missing[] = $relative;
            }
        }

        if ($missing) {
            throw new \RuntimeException(sprintf(
                'WingsOfWin seed image asset(s) missing for variant "%s": %s',
                $variant->user_defined_id,
                implode(', ', $missing)
            ));
        }

        if (! $attached) {
            throw new \RuntimeException(sprintf(
                'WingsOfWin seed could not attach variant image assets for "%s".',
                $variant->user_defined_id
            ));
        }

        $variant->image_set_id = $set->id;
        $variant->save();
    }

    private function attachSingleImage(Product $product, string $path, string $name): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $set = ImageSet::where('product_id', $product->id)->where('name', $name)->first();
        if (! $set) {
            $set = ImageSet::create([
                'product_id' => $product->id,
                'name' => $name,
                'is_main_set' => true,
            ]);
        }
        $set->images()->add($set->images()->make()->fromFile($path));

        return true;
    }

    private function attachDownload(Product $product, string $path, string $displayName, ?Variant $variant = null): void
    {
        if (! is_file($path)) {
            return;
        }

        $file = new ProductFile();
        $file->product_id = $product->id;
        $file->version = '1.0';
        $file->display_name = $displayName;
        $file->forceSave();
        $file->file()->add($file->file()->make()->fromFile($path));

        if ($variant) {
            $file->variants()->syncWithoutDetaching([$variant->id]);
        }
    }

    private function ensureAssets(): void
    {
        $imageRoot = base_path(self::ROOT . '/images');
        foreach (['products', 'services'] as $dir) {
            if (! is_dir($imageRoot . '/' . $dir)) {
                mkdir($imageRoot . '/' . $dir, 0775, true);
            }
        }
        $downloadRoot = base_path(self::ROOT . '/downloads');
        if (! is_dir($downloadRoot)) {
            mkdir($downloadRoot, 0775, true);
        }

        if (! is_file($this->logoImagePath()) && is_file($this->sourcePath('vector/wings-of-win-logo.svg'))) {
            copy($this->sourcePath('vector/wings-of-win-logo.svg'), $this->logoImagePath());
        }

        foreach ([10, 30, 50, 100] as $amount) {
            file_put_contents($downloadRoot . '/gift-credit-' . $amount . '.txt', 'WingsOfWin gift credit $' . $amount . '. Use this code manually with store support after purchase.');
        }
    }

    private function csv(string $file): array
    {
        $path = base_path(self::ROOT . '/csv/' . $file);
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) === 1 && trim((string)$row[0]) === '') {
                continue;
            }
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            if (count($row) > count($headers)) {
                $row = array_slice($row, 0, count($headers));
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($handle);

        return $rows;
    }

    private function cleanDescription(string $html): string
    {
        $html = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html) ?? '';
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $html) ?? '';
        $html = preg_replace('~\[contactform[^\]]*\]~i', '', $html) ?? '';
        $html = preg_replace('~\[video[^\]]*\]~i', '', $html) ?? '';
        $html = preg_replace('~\sstyle="[^"]*"~i', '', $html) ?? '';
        $html = preg_replace('~\sclass="[^"]*\b(?:accordionwin|toggle|item)\b[^"]*"~i', '', $html) ?? '';
        $html = preg_replace('~\son[a-z]+="[^"]*"~i', '', $html) ?? '';
        $html = preg_replace('~\s(?:href|src)="\s*javascript:[^"]*"~i', '', $html) ?? '';
        $html = preg_replace('~</?(?:font|center)[^>]*>~i', '', $html) ?? '';
        $html = str_replace('scarves@wingsofwin.com', 'store support', $html);
        $html = str_ireplace(['accordionwin', '.accordionwin'], '', $html);
        $html = preg_replace('~<div>\s*</div>~i', '', $html) ?? '';
        $html = trim($html);

        return $html !== '' ? $html : '<p>Curated WingsOfWin product.</p>';
    }

    private function shortText(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->cleanDescription($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_substr($text !== '' ? $text : 'Curated WingsOfWin product.', 0, 180);
    }

    private function videoEmbeds(array $row): array
    {
        $urls = array_filter(array_map('trim', explode('|', $row['video_urls'] ?? '')));

        return array_map(fn ($url) => ['title' => 'Product video', 'code' => $url], $urls);
    }

    private function money(?string $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return (int)round(((float)preg_replace('/[^0-9.]/', '', $value)) * 100);
    }

    private function normalizedImportedPrice(array $row, ?array $parent = null): ?int
    {
        $sourcePrice = $this->money($row['price'] ?: $row['regular_price']) ?? ($parent ? $this->money($parent['price'] ?: $parent['regular_price']) : null);

        if (! $this->isScarfRow($row, $parent)) {
            return $sourcePrice;
        }

        $quantity = $this->scarfQuantity($row, $parent);
        $material = $this->scarfMaterial($row, $parent);
        $unit = match ($material) {
            'silk' => $quantity > 1 ? 11900 : 14900,
            'wool' => 13900,
            default => 5900,
        };

        return $quantity * $unit;
    }

    private function displayTitle(array $row): string
    {
        if ($this->isWoolScarfRow($row)) {
            return str_ireplace('Silk Fiber', 'Wool Fiber', $row['title']);
        }

        return $row['title'];
    }

    private function displaySlug(array $row): string
    {
        if ($this->isWoolScarfRow($row)) {
            return $this->slug($this->displayTitle($row));
        }

        return $this->slug($row['slug'] ?: $this->displayTitle($row));
    }

    private function descriptionHtml(array $row): string
    {
        if ($this->isWoolScarfRow($row) && trim((string)$row['html_description']) === '') {
            return '<p>Warm black Gothic wings scarf made with soft wool fiber. Designed as a cozy wrap shawl for cool weather.</p>';
        }

        return $this->cleanDescription($row['html_description']);
    }

    private function isScarfRow(array $row, ?array $parent = null): bool
    {
        $text = strtolower(implode(' ', [
            $row['title'] ?? '',
            $row['categories'] ?? '',
            $row['category_slugs'] ?? '',
            $parent['title'] ?? '',
            $parent['categories'] ?? '',
            $parent['category_slugs'] ?? '',
        ]));

        return str_contains($text, 'scarf')
            || str_contains($text, 'scarves')
            || str_contains($text, 'shawl')
            || str_contains($text, 'shawls')
            || str_contains($text, 'sarong')
            || str_contains($text, 'wrap');
    }

    private function isWoolScarfRow(array $row): bool
    {
        $text = strtolower(implode(' ', [
            $row['title'] ?? '',
            $row['categories'] ?? '',
            $row['category_slugs'] ?? '',
        ]));

        return str_contains($text, 'wool');
    }

    private function scarfMaterial(array $row, ?array $parent = null): string
    {
        $rowAttributes = array_merge($this->attributeValues($row, 'Fabric'), $this->attributeValues($row, 'Quantity'));
        $parentAttributes = $rowAttributes ? [] : array_merge(
            $parent ? $this->attributeValues($parent, 'Fabric') : [],
            $parent ? $this->attributeValues($parent, 'Quantity') : []
        );

        $text = strtolower(implode(' ', array_merge($rowAttributes, $parentAttributes, [
            $row['title'] ?? '',
            $row['categories'] ?? '',
            $parent['title'] ?? '',
            $parent['categories'] ?? '',
        ])));

        if (str_contains($text, 'wool')) {
            return 'wool';
        }

        if (str_contains($text, 'silk')) {
            return 'silk';
        }

        return 'cotton';
    }

    private function scarfQuantity(array $row, ?array $parent = null): int
    {
        $text = strtolower(implode(' ', array_merge(
            $this->attributeValues($row, 'Quantity'),
            $parent ? $this->attributeValues($parent, 'Quantity') : [],
            [$row['title'] ?? '', $parent['title'] ?? '']
        )));

        if (preg_match('/(\d+)\s*(?:piece|pc|set)/', $text, $match)) {
            return max(1, (int)$match[1]);
        }

        return str_contains($text, 'mother and daughter set') || str_contains($text, 'christmas scarves set') ? 2 : 1;
    }

    private function attributeValues(array $row, string $label): array
    {
        $values = $this->decoded($row['attributes_flat_json'] ?? '{}')[$label] ?? [];

        return array_map('strval', (array)$values);
    }

    private function decoded(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function slug(string $value): string
    {
        return str_slug($value);
    }

    private function headline(string $value): string
    {
        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace(['-', '_'], ' ', $value)));
    }

    private function imagePath(string $relative): string
    {
        return base_path(self::ROOT . '/images/' . $relative);
    }

    private function logoImagePath(): string
    {
        return $this->imagePath('products/wings-of-win-logo.svg');
    }

    private function serviceImagePath(): string
    {
        return $this->logoImagePath();
    }

    private function downloadPath(string $relative): string
    {
        return base_path(self::ROOT . '/downloads/' . $relative);
    }

    private function sourcePath(string $relative): string
    {
        return base_path(self::ROOT . '/sources/' . $relative);
    }
}
