<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Hashids\Hashids as Hasher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CartProduct;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ServiceOption;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Variant;
use Validator;

class CartApiService
{
    public function __construct(
        private readonly CartResource $resource
    ) {
    }

    public function get(array $input, ?CommerceContext $context = null): array
    {
        return ['cart' => $this->resource->cart($this->cart($input, $context))];
    }

    public function addItem(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'product_id' => 'required',
            'variant_id' => 'nullable',
            'quantity' => 'nullable|integer|min:1',
            'service_option_ids' => 'nullable|array',
            'service_options_per_quantity' => 'nullable|boolean',
        ]);

        $cart = $this->cart($input, $context);
        $productQuery = Product::published()->withoutServiceCarriers();
        $allowedProductIds = app(CatalogApiService::class)->contextProductIds($context);

        if ($allowedProductIds !== null) {
            $productQuery->whereIn('id', $allowedProductIds ?: [0]);
        }

        $product = $productQuery->findOrFail($this->modelId((string)$input['product_id'], 'product'));
        $variant = null;

        if (!empty($input['variant_id'])) {
            $variant = Variant::published()
                ->where('product_id', $product->id)
                ->findOrFail($this->modelId((string)$input['variant_id'], 'variant'));
        }

        $serviceOptionIds = $this->serviceOptionIds((array)($input['service_option_ids'] ?? []));

        try {
            $item = $cart->addProduct(
                $product,
                (int)($input['quantity'] ?? $product->quantity_default ?? 1),
                $variant,
                Collection::make(),
                $serviceOptionIds,
                (bool)($input['service_options_per_quantity'] ?? true)
            );
        } catch (OutOfStockException) {
            throw new ValidationException(['quantity' => trans('kodzero.posmall::lang.common.stock_limit_reached')]);
        }

        $cart->refresh();

        return [
            'added_item' => $this->resource->item($item),
            'cart' => $this->resource->cart($cart),
        ];
    }

    public function setQuantity(array $input, int $itemId, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = $this->cart($input, $context);
        $item = $this->cartItem($cart, $itemId);
        $cart->setQuantity($item->id, (int)$input['quantity']);
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function removeItem(array $input, int $itemId, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
        ]);

        $cart = $this->cart($input, $context);
        $cart->removeProduct($this->cartItem($cart, $itemId));
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function applyDiscount(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'code' => 'required|string',
        ]);

        $cart = $this->cart($input, $context);
        $cart->applyDiscountByCode((string)$input['code'], 0);
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function setShippingMethod(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'shipping_method_id' => 'required|integer|exists:kodzero_posmall_shipping_methods,id',
        ]);

        $cart = $this->cart($input, $context);
        $method = ShippingMethod::findOrFail((int)$input['shipping_method_id']);
        $cart->setShippingMethod($method);
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function setPaymentMethod(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'payment_method_id' => 'required|integer|exists:kodzero_posmall_payment_methods,id',
        ]);

        $cart = $this->cart($input, $context);
        $cart->setPaymentMethod(PaymentMethod::findOrFail((int)$input['payment_method_id']));
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function setAddress(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'address_id' => 'required|integer|exists:kodzero_posmall_addresses,id',
            'type' => 'required|in:shipping,billing,both',
        ]);

        $cart = $this->cart($input, $context);
        $address = Address::where('customer_id', (int)$input['customer_id'])->findOrFail((int)$input['address_id']);

        if (in_array($input['type'], ['shipping', 'both'], true)) {
            $cart->setShippingAddress($address);
        }

        if (in_array($input['type'], ['billing', 'both'], true)) {
            $cart->setBillingAddress($address);
        }

        $cart->save();
        $cart->refresh();

        return ['cart' => $this->resource->cart($cart)];
    }

    public function shippingMethods(array $input, ?CommerceContext $context = null): array
    {
        $cart = $this->cart($input, $context);

        return [
            'methods' => ShippingMethod::getAvailableByCart($cart)
                ->map(fn ($method) => $this->resource->method($method))
                ->values()
                ->all(),
        ];
    }

    public function paymentMethods(array $input, ?CommerceContext $context = null): array
    {
        $cart = $this->cart($input, $context);

        return [
            'methods' => PaymentMethod::getAvailableByCart($cart)
                ->map(fn ($method) => $this->resource->method($method))
                ->values()
                ->all(),
        ];
    }

    public function cart(array $input, ?CommerceContext $context = null): Cart
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
        ]);

        $this->assertCustomerAllowed((int)$input['customer_id'], $context);
        $customer = Customer::with('addresses')->findOrFail((int)$input['customer_id']);
        $cart = Cart::orderBy('created_at', 'DESC')->firstOrNew(['customer_id' => $customer->id]);

        if (!$cart->shipping_address_id) {
            $cart->shipping_address_id = $customer->default_shipping_address_id ?: $customer->default_billing_address_id;
        }

        if (!$cart->billing_address_id) {
            $cart->billing_address_id = $customer->default_billing_address_id;
        }

        if (!$cart->exists) {
            $cart->save();
        }

        return $cart;
    }

    private function cartItem(Cart $cart, int $itemId): CartProduct
    {
        $cart->loadMissing('products');

        $item = $cart->products->firstWhere('id', $itemId);

        if (!$item) {
            throw new ValidationException(['item_id' => 'Cart item does not belong to the requested customer cart.']);
        }

        return $item;
    }

    private function modelId(string $value, string $prefix): int
    {
        $value = trim($value);
        $prefix .= '-';

        if (str_starts_with($value, $prefix)) {
            $value = substr($value, strlen($prefix));
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        $decoded = app(Hasher::class)->decode($value);

        if (count($decoded) !== 1) {
            throw new ValidationException(['id' => 'Invalid POSMall hash id.']);
        }

        return (int)$decoded[0];
    }

    private function serviceOptionIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = collect($ids)
            ->map(fn ($id) => $this->modelId((string)$id, 'service-option'))
            ->unique()
            ->values()
            ->all();

        $found = ServiceOption::whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int)$id)->all();

        if (count($found) !== count($ids)) {
            throw new ValidationException(['service_option_ids' => 'One or more service options were not found.']);
        }

        return $found;
    }

    private function validate(array $input, array $rules): void
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function assertCustomerAllowed(int $customerId, ?CommerceContext $context): void
    {
        if ($context && !$context->token->allowsCustomerId($customerId)) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }
    }
}
