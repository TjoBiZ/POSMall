<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use October\Rain\Exception\ValidationException;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Models\Cart;
use Throwable;

/**
 * The DiscountApplier component allow the user to enter a discount code.
 */
class DiscountApplier extends POSMallComponent
{
    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.discountApplier.details.name',
            'description' => 'kodzero.posmall::lang.components.discountApplier.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        return [
            'discountCodeLimit' => [
                'type'    => 'string',
                'title'   => 'kodzero.posmall::lang.components.cart.properties.discountCodeLimit.title',
                'description' => 'kodzero.posmall::lang.components.cart.properties.discountCodeLimit.description',
                'default' => 0,
            ],
        ];
    }

    /**
     * A discount code has been entered.
     *
     * Applies the discount code directly to the Cart model.
     *
     * @throws ValidationException
     */
    public function onApplyDiscount()
    {
        $code = strtoupper(post('code') ?? '');
        $cart = Cart::byUser(Auth::user());

        try {
            $cart->applyDiscountByCode($code, (int)$this->property('discountCodeLimit'));
        } catch (Throwable $e) {
            throw new ValidationException([
                'code' => $e->getMessage(),
            ]);
        }

        Flash::success(trans('kodzero.posmall::lang.components.discountApplier.discount_applied'));
    }
}
