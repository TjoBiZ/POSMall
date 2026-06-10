<?php

namespace KodZero\POSMall\Classes\Registration;

use KodZero\POSMall\Components\AddressForm;
use KodZero\POSMall\Components\AddressList;
use KodZero\POSMall\Components\AddressSelector;
use KodZero\POSMall\Components\Cart;
use KodZero\POSMall\Components\Checkout;
use KodZero\POSMall\Components\CurrencyPicker;
use KodZero\POSMall\Components\CustomerProfile;
use KodZero\POSMall\Components\DiscountApplier;
use KodZero\POSMall\Components\EnhancedEcommerceAnalytics;
use KodZero\POSMall\Components\POSMallDependencies;
use KodZero\POSMall\Components\MyAccount;
use KodZero\POSMall\Components\OrdersList;
use KodZero\POSMall\Components\PaymentMethodSelector;
use KodZero\POSMall\Components\Product as ProductComponent;
use KodZero\POSMall\Components\ProductReviews;
use KodZero\POSMall\Components\ProductSearch;
use KodZero\POSMall\Components\Products as ProductsComponent;
use KodZero\POSMall\Components\ProductsFilter;
use KodZero\POSMall\Components\QuickCheckout;
use KodZero\POSMall\Components\Services;
use KodZero\POSMall\Components\ShippingMethodSelector;
use KodZero\POSMall\Components\SignUp;
use KodZero\POSMall\Components\WishlistButton;
use KodZero\POSMall\Components\Wishlists;
use KodZero\POSMall\FormWidgets\Price;
use KodZero\POSMall\FormWidgets\PropertyFields;

trait BootComponents
{
    public function registerComponents()
    {
        return [
            Cart::class                       => 'posmallCart',
            SignUp::class                     => 'posmallSignUp',
            ShippingMethodSelector::class     => 'posmallShippingMethodSelector',
            AddressSelector::class            => 'posmallAddressSelector',
            AddressForm::class                => 'posmallAddressForm',
            PaymentMethodSelector::class      => 'posmallPaymentMethodSelector',
            Checkout::class                   => 'posmallCheckout',
            QuickCheckout::class              => 'posmallQuickCheckout',
            ProductsComponent::class          => 'posmallProducts',
            ProductsFilter::class             => 'posmallProductsFilter',
            ProductComponent::class           => 'posmallProduct',
            ProductSearch::class              => 'posmallProductSearch',
            Services::class                   => 'posmallServices',
            DiscountApplier::class            => 'posmallDiscountApplier',
            MyAccount::class                  => 'posmallMyAccount',
            OrdersList::class                 => 'posmallOrdersList',
            CustomerProfile::class            => 'posmallCustomerProfile',
            AddressList::class                => 'posmallAddressList',
            CurrencyPicker::class             => 'posmallCurrencyPicker',
            POSMallDependencies::class           => 'posmallDependencies',
            EnhancedEcommerceAnalytics::class => 'posmallEnhancedEcommerceAnalytics',
            Wishlists::class                  => 'posmallWishlists',
            WishlistButton::class             => 'posmallWishlistButton',
            ProductReviews::class             => 'posmallProductReviews',
        ];
    }

    public function registerFormWidgets()
    {
        return [
            PropertyFields::class => 'posmall.propertyfields',
            Price::class          => 'posmall.price',
        ];
    }
}
