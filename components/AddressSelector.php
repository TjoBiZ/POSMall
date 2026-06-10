<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use October\Rain\Exception\ValidationException;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\GeneralSettings;
use Validator;

/**
 * The AddressSelector component displays a dropdown
 * to select an address.
 */
class AddressSelector extends POSMallComponent
{
    /**
     * The user's cart.
     *
     * @var Cart
     */
    public $cart;

    /**
     * All the user's addresses.
     *
     * @var Collection
     */
    public $addresses;

    /**
     * The currently active address.
     * This will be displayed as a full string representation.
     *
     * @var Address
     */
    public $address;

    /**
     * The type of the address (billing, shipping).
     *
     * @var string
     */
    public $type;

    /**
     * The currently active Address in the selection dropdown.
     *
     * @var Address
     */
    public $activeAddress;

    /**
     * The name of the address edit page.
     *
     * @var string
     */
    public $addressPage;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.addressSelector.details.name',
            'description' => 'kodzero.posmall::lang.components.addressSelector.details.description',
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
            'type' => [
                'label' => 'Type',
                'type'  => 'dropdown',
            ],
            'redirect' => [
                'label' => 'Redirect',
                'type'  => 'string',
                'default' => 'checkout',
            ],
        ];
    }

    /**
     * Options array for the type dropdown.
     *
     * @return array
     */
    public function getTypeOptions()
    {
        return [
            'shipping' => trans('kodzero.posmall::lang.order.shipping_address'),
            'billing'  => trans('kodzero.posmall::lang.order.billing_address'),
        ];
    }

    /**
     * The component is initialized.
     *
     * @return void
     */
    public function init()
    {
        $user = Auth::user();

        if ($user) {
            $this->setVar('cart', Cart::byUser($user));
        }
    }

    /**
     * The component is executed.
     *
     * @return RedirectResponse
     */
    public function onRun()
    {
        $this->setData();

        if (Auth::user() && $this->addresses && $this->addresses->count() < 1) {
            Flash::warning(trans('kodzero.posmall::frontend.flash.missing_address'));

            $url = $this->controller->pageUrl($this->addressPage, [
                'address'  => 'new',
                'redirect' => 'payment',
                'set'      => 'both',
            ]);

            return response()->redirectTo($url);
        }
    }

    /**
     * The user wants to select another address.
     *
     * Display a dropdown of all available addresses.
     *
     * @return void
     */
    public function onChangeAddress()
    {
        $user = Auth::user();
        $this->setData();
        $customer = $this->ensureCustomerForUser($user);

        if (! $user || ! $customer) {
            return;
        }

        $this->setVar('addresses', Address::byCustomer($customer)->get());
        $this->setVar('activeAddress', $this->cart->{$this->type . '_address_id'});
    }

    /**
     * The user selected a new address.
     *
     * @throws ValidationException
     * @return array
     */
    public function onUpdateAddress()
    {
        $user = Auth::user();
        $this->setData();
        $customer = $this->ensureCustomerForUser($user);

        if (! $user || ! $customer) {
            throw new ValidationException(['id' => trans('kodzero.posmall::lang.components.addressList.errors.address_not_found')]);
        }

        $data  = post();
        $rules = [
            'id' => [
                'required',
                Rule::exists('kodzero_posmall_addresses')->where(function ($q) use ($customer) {
                    $q->where('customer_id', $customer->id);
                }),
            ],
        ];

        $validation = Validator::make($data, $rules);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $col = $this->type . '_address_id';

        $cart         = Cart::byUser($user);
        $cart->{$col} = $data['id'];
        $cart->save();

        $selector = '.mall-address-selector--' . $this->type;
        $partial  = $this->alias . '::selector';

        $this->cart = $cart;
        $this->setData();

        return [$selector => $this->renderPartial($partial)];
    }

    /**
     * This method sets all variables needed for this component to work.
     *
     * @return bool
     */
    protected function setData()
    {
        $user = Auth::user();

        if (! $user) {
            $this->setVar('addresses', collect([]));
            return;
        }

        $customer = $this->ensureCustomerForUser($user);

        if (! $customer) {
            $this->setVar('addresses', collect([]));
            return;
        }

        $this->setVar('cart', Cart::byUser($user));

        $this->setVar('type', $this->property('type'));

        if ($this->type === 'billing') {
            $address = $this->cart->billing_address_id ?? $customer->default_billing_address_id;
        } else {
            $address = $this->cart->shipping_address_id ?? $customer->default_shipping_address_id;
        }

        $addresses = Address::byCustomer($customer)->get();
        $address   = $addresses->where('id', $address)->first();

        $this->setVar('addresses', $addresses);
        $this->setVar('address', $address);
        $this->setVar('addressPage', GeneralSettings::get('address_page'));
    }
}
