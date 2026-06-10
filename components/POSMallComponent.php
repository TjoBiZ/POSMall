<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Cms\Classes\ComponentBase;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Models\Customer;

/**
 * This is the base class of all KodZero.POSMall components.
 */
abstract class POSMallComponent extends ComponentBase
{
    use HashIds;

    protected function setVar($name, $value)
    {
        if (property_exists($this, $name)) {
            return $this->$name = $this->page[$name] = $value;
        }
    }

    protected function ensureCustomerForUser($user): ?Customer
    {
        if (! $user) {
            return null;
        }

        $customer = Customer::ensureForUser($user) ?? Customer::forUser($user);

        if (method_exists($user, 'setRelation')) {
            $user->setRelation('posmall_customer', $customer);
        }

        if ($customer) {
            PosmallUserGroup::attach($user);
        }

        return $customer;
    }
}
