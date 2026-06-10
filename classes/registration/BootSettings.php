<?php

namespace KodZero\POSMall\Classes\Registration;

use Backend\Facades\Backend;
use KodZero\POSMall\Models\ApiSettings;
use KodZero\POSMall\Models\FeedSettings;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\PaymentGatewaySettings;
use KodZero\POSMall\Models\ReviewSettings;

trait BootSettings
{
    public function registerSettings()
    {
        return [
            'general_settings'          => [
                'label'       => 'kodzero.posmall::lang.general_settings.label',
                'description' => 'kodzero.posmall::lang.general_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-shopping-cart',
                'class'       => GeneralSettings::class,
                'order'       => 0,
                'permissions' => ['kodzero.posmall.settings.manage_general'],
                'keywords'    => 'shop store mall general',
                'size'        => 'huge',
            ],
            'currency_settings'         => [
                'label'       => 'kodzero.posmall::lang.currency_settings.label',
                'description' => 'kodzero.posmall::lang.currency_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-money',
                'url'         => Backend::url('kodzero/posmall/currencies'),
                'order'       => 20,
                'permissions' => ['kodzero.posmall.settings.manage_currency'],
                'keywords'    => 'shop store mall currency',
            ],
            'price_categories_settings' => [
                'label'       => 'kodzero.posmall::lang.price_category_settings.label',
                'description' => 'kodzero.posmall::lang.price_category_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-pie-chart',
                'url'         => Backend::url('kodzero/posmall/pricecategories'),
                'order'       => 20,
                'permissions' => ['kodzero.posmall.manage_price_categories'],
                'keywords'    => 'shop store mall currency price categories',
            ],
            'tax_settings'              => [
                'label'       => 'kodzero.posmall::lang.common.taxes',
                'description' => 'kodzero.posmall::lang.tax_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-percent',
                'url'         => Backend::url('kodzero/posmall/taxes'),
                'order'       => 40,
                'permissions' => ['kodzero.posmall.manage_taxes'],
                'keywords'    => 'shop store mall tax taxes',
            ],
            'notification_settings'     => [
                'label'       => 'kodzero.posmall::lang.notification_settings.label',
                'description' => 'kodzero.posmall::lang.notification_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-envelope',
                'url'         => Backend::url('kodzero/posmall/notifications'),
                'order'       => 40,
                'permissions' => ['kodzero.posmall.manage_notifications'],
                'keywords'    => 'shop store mall notifications email mail',
            ],
            'feed_settings'             => [
                'label'       => 'kodzero.posmall::lang.common.feeds',
                'description' => 'kodzero.posmall::lang.feed_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-rss',
                'class'       => FeedSettings::class,
                'order'       => 50,
                'permissions' => ['kodzero.posmall.manage_feeds'],
                'keywords'    => 'shop store mall feeds',
            ],
            'api_settings'              => [
                'label'       => 'POSMall API',
                'description' => 'Controlled external/client API settings',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-key',
                'class'       => ApiSettings::class,
                'order'       => 55,
                'permissions' => ['kodzero.posmall.manage_api'],
                'keywords'    => 'posmall api rest graphql token integration',
                'size'        => 'large',
            ],
            'review_settings'           => [
                'label'       => 'kodzero.posmall::lang.common.reviews',
                'description' => 'kodzero.posmall::lang.review_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category',
                'icon'        => 'icon-star',
                'class'       => ReviewSettings::class,
                'order'       => 60,
                'permissions' => ['kodzero.posmall.manage_reviews'],
                'keywords'    => 'shop store mall reviews',
            ],
            'payment_gateways_settings' => [
                'label'       => 'kodzero.posmall::lang.payment_gateway_settings.label',
                'description' => 'kodzero.posmall::lang.payment_gateway_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category_orders',
                'icon'        => 'icon-credit-card',
                'class'       => PaymentGatewaySettings::class,
                'order'       => 30,
                'permissions' => ['kodzero.posmall.settings.manage_payment_gateways'],
                'keywords'    => 'shop store mall payment gateways',
            ],
            'payment_method_settings'   => [
                'label'       => 'kodzero.posmall::lang.common.payment_methods',
                'description' => 'kodzero.posmall::lang.payment_method_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category_orders',
                'icon'        => 'icon-money',
                'url'         => Backend::url('kodzero/posmall/paymentmethods'),
                'order'       => 40,
                'permissions' => ['kodzero.posmall.settings.manage_payment_methods'],
                'keywords'    => 'shop store mall payment methods',
            ],
            'shipping_method_settings'  => [
                'label'       => 'kodzero.posmall::lang.common.shipping_methods',
                'description' => 'kodzero.posmall::lang.shipping_method_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category_orders',
                'icon'        => 'icon-truck',
                'url'         => Backend::url('kodzero/posmall/shippingmethods'),
                'order'       => 40,
                'permissions' => ['kodzero.posmall.manage_shipping_methods'],
                'keywords'    => 'shop store mall shipping methods',
            ],
            'order_state_settings'      => [
                'label'       => 'kodzero.posmall::lang.common.order_states',
                'description' => 'kodzero.posmall::lang.order_state_settings.description',
                'category'    => 'kodzero.posmall::lang.general_settings.category_orders',
                'icon'        => 'icon-history',
                'url'         => Backend::url('kodzero/posmall/orderstate'),
                'order'       => 50,
                'permissions' => ['kodzero.posmall.manage_order_states'],
                'keywords'    => 'shop store mall notifications email mail',
            ],
        ];
    }
}
