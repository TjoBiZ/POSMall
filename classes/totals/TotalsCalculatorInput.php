<?php

namespace KodZero\POSMall\Classes\Totals;

use Illuminate\Support\Collection;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Wishlist;

class TotalsCalculatorInput
{
    /**
     * @var Collection<Product>
     */
    public $products;

    /**
     * @var ShippingMethod
     */
    public $shipping_method;

    /**
     * @var Collection<Discount>
     */
    public $discounts;

    /**
     * @var PaymentMethod
     */
    public $payment_method;

    /**
     * @var int
     */
    public $shipping_country_id;

    /**
     * @var int|null
     */
    public $shipping_state_id;

    /**
     * @var string|null
     */
    public $shipping_state_code;

    /**
     * @var string|null
     */
    public $shipping_zip;

    /**
     * Create an instance from a Cart model.
     *
     * @param Cart $cart
     *
     * @return TotalsCalculatorInput
     */
    public static function fromCart(Cart $cart)
    {
        $cart->loadMissing(
            'products',
            'products.data.taxes',
            'products.data.taxes.states',
            'shipping_method',
            'shipping_method.taxes.countries',
            'shipping_method.taxes.states',
            'shipping_method.rates',
            'discounts'
        );

        $input                      = new self();
        $input->products            = $cart->products;
        $input->shipping_method     = $cart->shipping_method;
        $input->payment_method      = $cart->payment_method;
        $input->discounts           = $cart->discounts;
        $shippingAddress = optional($cart)->shipping_address;

        $input->shipping_country_id = optional($shippingAddress)->country_id
            ?? $cart->getFallbackShippingCountryId();
        $input->shipping_state_id = optional($shippingAddress)->state_id
            ?? $cart->getFallbackShippingStateId();
        $input->shipping_state_code = optional($shippingAddress)->state_code;
        $input->shipping_zip = optional($shippingAddress)->zip;

        return $input;
    }

    public static function fromWishlist(Wishlist $wishlist)
    {
        $wishlist->loadMissing('items.data.taxes');

        $input                      = new self();
        $input->products            = $wishlist->items;
        $input->discounts           = new Collection();
        $input->shipping_method     = $wishlist->shipping_method;
        $input->shipping_country_id = $wishlist->getCartCountryId();
        $input->shipping_state_id   = null;
        $input->shipping_state_code = null;
        $input->shipping_zip        = null;

        return $input;
    }
}
