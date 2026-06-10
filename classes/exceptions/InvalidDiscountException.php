<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Exceptions;

use Illuminate\Support\Collection;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Discount;
use RuntimeException;

class InvalidDiscountException extends RuntimeException
{
    /**
     * @var Cart
     */
    public $cart;

    /**
     * @var Collection<Discount>
     */
    public $discounts;

    /**
     * Create a new exception.
     */
    public function __construct(Cart $cart, Collection $discounts)
    {
        $this->cart = $cart;
        $this->discounts = $discounts;

        parent::__construct(
            'Used discounts are no longer valid.',
            422
        );
    }
}
