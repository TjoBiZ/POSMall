<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Taxes\UsaTaxCheckoutGuard;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\ShippingMethod;
use Validator;

class CheckoutApiService
{
    public function __construct(
        private readonly CartApiService $carts,
        private readonly OrderResource $orders,
        private readonly PaymentLinkService $paymentLinks
    ) {
    }

    public function createOrder(array $input, CommerceContext $context): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'idempotency_key' => 'nullable|string|max:191',
            'customer_notes' => 'nullable|string|max:1000',
            'create_payment_link' => 'nullable|boolean',
        ]);

        if (!empty($input['idempotency_key'])) {
            $existing = Order::where('api_source', 'api')
                ->where('api_token_id', $context->token->id)
                ->where('customer_id', (int)$input['customer_id'])
                ->where('api_idempotency_key', (string)$input['idempotency_key'])
                ->where('vendor_id', optional($context->vendor)->id)
                ->where('channel_id', optional($context->channel)->id)
                ->where('warehouse_id', optional($context->warehouse)->id)
                ->latest('id')
                ->first();

            if ($existing) {
                return $this->orderResponse($existing, false, (bool)($input['create_payment_link'] ?? true));
            }
        }

        $cart = $this->carts->cart($input, $context);

        if (!$cart->payment_method_id && ($method = PaymentMethod::getDefault())) {
            $cart->setPaymentMethod($method);
        }

        if (!$cart->shipping_method_id && !$cart->is_virtual && ($method = ShippingMethod::getDefault())) {
            $cart->setShippingMethod($method);
        }

        app(UsaTaxCheckoutGuard::class)->validate($cart);

        $attributes = $context->orderAttributes() + [
            'api_idempotency_key' => $input['idempotency_key'] ?? null,
        ];

        if (!empty($input['customer_notes'])) {
            $attributes['customer_notes'] = strip_tags((string)$input['customer_notes']);
        }

        $order = Order::fromCart($cart, $attributes);

        return $this->orderResponse($order, true, (bool)($input['create_payment_link'] ?? true));
    }

    private function orderResponse(Order $order, bool $created, bool $createPaymentLink): array
    {
        $response = [
            'created' => $created,
            'order' => $this->orders->order($order),
        ];

        if ($createPaymentLink) {
            $response['payment_link'] = $created
                ? $this->paymentLinks->create($order)
                : $this->paymentLinks->reuseOrCreate($order);
        }

        return $response;
    }

    private function validate(array $input, array $rules): void
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
