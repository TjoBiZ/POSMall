<?php

namespace KodZero\POSMall\Classes\Registration;

use Event;
use KodZero\POSMall\Classes\Events\MailingEventHandler;
use KodZero\POSMall\Classes\Search\ProductsSearchProvider;
use KodZero\POSMall\Models\Brand;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ProductPrice;
use KodZero\POSMall\Models\PropertyValue;
use KodZero\POSMall\Models\Variant;
use KodZero\POSMall\Models\Wishlist;

trait BootEvents
{
    public function registerObservers()
    {
        Product::observe(\KodZero\POSMall\Classes\Observers\ProductObserver::class);
        Variant::observe(\KodZero\POSMall\Classes\Observers\VariantObserver::class);
        Brand::observe(\KodZero\POSMall\Classes\Observers\BrandObserver::class);
        PropertyValue::observe(\KodZero\POSMall\Classes\Observers\PropertyValueObserver::class);
        ProductPrice::observe(\KodZero\POSMall\Classes\Observers\ProductPriceObserver::class);
    }

    protected function registerEvents()
    {
        $this->registerObservers();
        $this->registerGenericEvents();
        $this->registerStaticPagesEvents();
        $this->registerSiteSearchEvents();
        $this->registerGdprEvents();
    }

    protected function registerGenericEvents()
    {
        $this->app->bind(MailingEventHandler::class, fn () => new MailingEventHandler());

        $this->app['events']->subscribe(MailingEventHandler::class);
    }

    protected function registerStaticPagesEvents()
    {
        $listTypes = fn () => [
            'mall-category' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.single_category'),
            'mall-product' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.single_product'),
            'mall-variant' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.single_variant'),
            'all-mall-categories' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.all_categories'),
            'all-mall-products' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.all_products'),
            'all-mall-variants' => '[POSMall] ' . trans('kodzero.posmall::lang.menu_items.all_variants'),
        ];

        $getTypeInfo = function ($type) {
            if ($type === 'all-mall-categories' || $type === 'mall-category') {
                return Category::getMenuTypeInfo($type);
            }

            if ($type === 'mall-product' || $type === 'mall-variant') {
                return Product::getMenuTypeInfo($type);
            }

            if ($type === 'all-mall-products' || $type === 'all-mall-variants') {
                return [
                    'dynamicItems' => true,
                ];
            }

            return null;
        };

        $resolveItem = function ($type, $item, $url, $theme) {
            if ($type === 'all-mall-categories') {
                return Category::resolveCategoriesItem($item, $url, $theme);
            }

            if ($type === 'mall-category') {
                return Category::resolveCategoryItem($item, $url, $theme);
            }

            if ($type === 'all-mall-products' || $type === 'mall-product' || $type === 'mall-variant') {
                return Product::resolveItem($item, $url, $theme, $type);
            }

            if ($type === 'all-mall-variants') {
                return Variant::resolveItem($item, $url, $theme);
            }

            return null;
        };

        // RainLab.Pages
        Event::listen('pages.menuitem.listTypes', $listTypes);
        Event::listen('pages.menuitem.getTypeInfo', $getTypeInfo);
        Event::listen('pages.menuitem.resolveItem', $resolveItem);

        // October 3 CMS Module
        Event::listen('cms.pageLookup.listTypes', $listTypes);
        Event::listen('cms.pageLookup.getTypeInfo', $getTypeInfo);
        Event::listen('cms.pageLookup.resolveItem', $resolveItem);

        // Translate slugs
        Event::listen('translate.localePicker.translateParams', function ($page, $params, $oldLocale, $newLocale) {
            if ($page->getBaseFileName() === GeneralSettings::get('category_page')) {
                return Category::translateParams($params, $oldLocale, $newLocale);
            }

            if ($page->getBaseFileName() === GeneralSettings::get('product_page')) {
                return Product::translateParams($params, $oldLocale, $newLocale);
            }
        });

        // Translate slugs October 3 CMS
        Event::listen('cms.sitePicker.overrideParams', function ($page, $params, $currentSite, $proposedSite) {
            if ($page->getBaseFileName() === GeneralSettings::get('category_page')) {
                return Category::translateParams($params, $currentSite->hard_locale, $proposedSite->hard_locale);
            }

            if ($page->getBaseFileName() === GeneralSettings::get('product_page')) {
                return Product::translateParams($params, $currentSite->hard_locale, $proposedSite->hard_locale);
            }
        });
    }

    protected function registerSiteSearchEvents()
    {
        Event::listen('offline.sitesearch.extend', fn () => new ProductsSearchProvider());
    }

    protected function registerGdprEvents()
    {
        Event::listen('offline.gdpr::cleanup.register', fn () => [
            'id'     => 'kodzero-posmall-plugin',
            'label'  => 'POSMall',
            'models' => [
                [
                    'label'   => 'Customers',
                    'comment' => 'Delete inactive customer accounts (based on last login date)',
                    'class'   => Customer::class,
                ],
                [
                    'label'   => 'Orders',
                    'comment' => 'Delete completed orders',
                    'class'   => Order::class,
                ],
                [
                    'label'   => 'Carts',
                    'comment' => 'Delete abandoned shopping carts',
                    'class'   => Cart::class,
                ],
                [
                    'label'   => 'Favorite Lists',
                    'comment' => 'Delete old Favorite Lists of unregistered users',
                    'class'   => Wishlist::class,
                ],
            ],
        ]);
    }
}
