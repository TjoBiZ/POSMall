<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Auth\Access\AuthorizationException;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Customer;
use Validator;

class CustomerApiService
{
    public function get(int $customerId, ?CommerceContext $context = null): array
    {
        $this->assertCustomerAllowed($customerId, $context);
        $customer = Customer::with(['user', 'addresses'])->findOrFail($customerId);

        return ['customer' => $this->customer($customer)];
    }

    public function addresses(int $customerId, ?CommerceContext $context = null): array
    {
        $this->assertCustomerAllowed($customerId, $context);
        $customer = Customer::with('addresses')->findOrFail($customerId);

        return [
            'addresses' => $customer->addresses->map(fn (Address $address) => $address->toArray())->values()->all(),
        ];
    }

    public function createAddress(int $customerId, array $input, ?CommerceContext $context = null): array
    {
        $this->assertCustomerAllowed($customerId, $context);
        $customer = Customer::findOrFail($customerId);
        $data = $this->validatedAddress($input);
        $address = new Address($data);
        $address->customer_id = $customer->id;
        $address->save();

        $this->applyDefaultAddressFlags($customer, $address, $input);

        return ['address' => $address->fresh()->toArray()];
    }

    public function updateAddress(int $customerId, int $addressId, array $input, ?CommerceContext $context = null): array
    {
        $this->assertCustomerAllowed($customerId, $context);
        $customer = Customer::findOrFail($customerId);
        $address = Address::where('customer_id', $customer->id)->findOrFail($addressId);
        $address->fill($this->validatedAddress($input, false));
        $address->save();

        $this->applyDefaultAddressFlags($customer, $address, $input);

        return ['address' => $address->fresh()->toArray()];
    }

    private function customer(Customer $customer): array
    {
        return [
            'id' => (int)$customer->id,
            'name' => (string)$customer->name,
            'firstname' => (string)$customer->firstname,
            'lastname' => (string)$customer->lastname,
            'email' => (string)optional($customer->user)->email,
            'default_shipping_address_id' => $customer->default_shipping_address_id ? (int)$customer->default_shipping_address_id : null,
            'default_billing_address_id' => $customer->default_billing_address_id ? (int)$customer->default_billing_address_id : null,
            'addresses' => $customer->addresses->map(fn (Address $address) => $address->toArray())->values()->all(),
        ];
    }

    private function validatedAddress(array $input, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes|required';
        $rules = [
            'company' => 'nullable|string|max:191',
            'name' => 'nullable|string|max:191',
            'lines' => $required . '|string|max:1000',
            'zip' => $required . '|string|max:32',
            'city' => $required . '|string|max:191',
            'country_id' => $required . '|integer|exists:rainlab_location_countries,id',
            'state_id' => 'nullable|integer|exists:rainlab_location_states,id',
            'details' => 'nullable|string|max:1000',
            'delivery_notes' => 'nullable|string|max:1000',
        ];

        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return collect($input)
            ->only(['company', 'name', 'lines', 'zip', 'city', 'country_id', 'state_id', 'details', 'delivery_notes'])
            ->all();
    }

    private function applyDefaultAddressFlags(Customer $customer, Address $address, array $input): void
    {
        if ((bool)($input['default_shipping'] ?? false)) {
            $customer->default_shipping_address_id = $address->id;
        }

        if ((bool)($input['default_billing'] ?? false)) {
            $customer->default_billing_address_id = $address->id;
        }

        if ($customer->isDirty()) {
            $customer->save();
        }
    }

    private function assertCustomerAllowed(int $customerId, ?CommerceContext $context): void
    {
        if ($context && !$context->token->allowsCustomerId($customerId)) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }
    }
}
