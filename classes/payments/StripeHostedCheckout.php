<?php

namespace KodZero\POSMall\Classes\Payments;

use KodZero\POSMall\Models\PaymentGatewaySettings;
use Session;
use Throwable;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * Process the payment via Stripe Hosted Checkout (redirect mode).
 * 
 * Uses dynamic price mapping (price_data) to avoid needing Stripe Product/Price IDs.
 */
class StripeHostedCheckout extends PaymentProvider
{
    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'Stripe (Hosted Checkout)';
    }

    /**
     * {@inheritdoc}
     */
    public function identifier(): string
    {
        return 'stripe-hosted-checkout';
    }

    /**
     * {@inheritdoc}
     */
    public function validate(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function process(PaymentResult $result): PaymentResult
    {
        try {
            $stripe = $this->getStripeClient();
            $lineItems = $this->buildLineItems();
            $params = [
                'mode' => 'payment',
                'line_items' => $lineItems,
                'success_url' => $this->returnUrl(),
                'cancel_url' => $this->cancelUrl(),
                'client_reference_id' => (string) $this->order->id,
                'customer_email' => $this->getCustomerEmail(),
                'metadata' => [
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                    'payment_hash' => $this->order->payment_hash,
                ],
            ];

            $session = $stripe->checkout->sessions->create($params);
            // Store session ID for later verification
            Session::put('posmall.payment.callback', self::class);
            Session::put('posmall.stripe_checkout.session_id', $session->id);

            return $result->redirect($session->url);
        } catch (ApiErrorException $e) {
            return $result->fail([
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], $e);
        } catch (Throwable $e) {
            \Log::error($e->getMessage());
            return $result->fail([], $e);
        }
    }

    /**
     * Stripe Checkout has processed the payment and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function complete(PaymentResult $result): PaymentResult
    {
        $sessionId = Session::pull('posmall.stripe_checkout.session_id');

        if (!$sessionId) {
            return $result->fail([
                'msg' => 'Missing Stripe session ID',
            ], null);
        }

        $this->setOrder($result->order);

        try {
            $stripe = $this->getStripeClient();

            // Retrieve the Checkout Session to verify payment status
            $session = $stripe->checkout->sessions->retrieve($sessionId, [
                'expand' => ['payment_intent'],
            ]);

            $data = $this->sessionPaymentData($session);

            // Check if payment was successful
            if ($session->payment_status === 'paid') {
                if (! $this->sessionMatchesOrder($session)) {
                    return $result->fail($data + ['reason' => 'Stripe session does not match order.'], $session);
                }

                return $result->success($data, $session);
            }

            // Payment is not complete yet
            return $result->fail($data, $session);

        } catch (ApiErrorException $e) {
            return $result->fail([
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ], $e);
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function settings(): array
    {
        return [];
    }


    /**
     * Build the Stripe Client instance.
     *
     * @return StripeClient
     */
    protected function getStripeClient(): StripeClient
    {
        $secretKey = PaymentGatewaySettings::secret('stripe_api_key');

        return new StripeClient($secretKey);
    }

    /**
     * Build line_items array with dynamic price_data from order products.
     *
     * @return array
     */
    protected function buildLineItems(): array
    {
        return [
            [
                'price_data' => [
                    'currency' => strtolower($this->orderCurrencyCode()),
                    'unit_amount' => $this->stripeAmountForOrder(),
                    'product_data' => [
                        'name' => 'Order #' . $this->order->order_number,
                    ],
                ],
                'quantity' => 1,
            ]
        ];
    }

    public function sessionMatchesOrder($session): bool
    {
        $order = $this->order;

        if (! $order || ! $order->id) {
            return false;
        }

        $orderId = data_get($session, 'metadata.order_id') ?: data_get($session, 'client_reference_id');
        $hash = data_get($session, 'metadata.payment_hash');
        $amount = data_get($session, 'amount_total');
        $currency = strtolower((string)data_get($session, 'currency'));
        $orderCurrency = strtolower($this->orderCurrencyCode());
        $provider = optional($order->payment_method)->payment_provider;

        return (string)$orderId === (string)$order->id
            && (string)$hash === (string)$order->payment_hash
            && (int)$amount === $this->stripeAmountForOrder()
            && $currency === $orderCurrency
            && (! $provider || $provider === $this->identifier());
    }

    public function sessionPaymentData($session): array
    {
        $paymentIntent = data_get($session, 'payment_intent.id') ?: data_get($session, 'payment_intent');

        return [
            'session_id' => data_get($session, 'id'),
            'payment_status' => data_get($session, 'payment_status'),
            'payment_intent' => is_scalar($paymentIntent) ? $paymentIntent : null,
            'amount_total' => data_get($session, 'amount_total'),
            'currency' => data_get($session, 'currency'),
        ];
    }

    protected function stripeAmountForOrder(): int
    {
        return (int)$this->rawOrderValue('total_post_taxes');
    }

    protected function orderCurrencyCode(): string
    {
        $currency = $this->rawOrderValue('currency');

        if (is_string($currency)) {
            $decoded = json_decode($currency, true);
            $currency = is_array($decoded) ? $decoded : [];
        }

        if (is_array($currency) && isset($currency['code'])) {
            return (string)$currency['code'];
        }

        $currency = $this->order->currency;

        return is_array($currency) && isset($currency['code']) ? (string)$currency['code'] : 'gbp';
    }

    protected function rawOrderValue(string $key)
    {
        if (method_exists($this->order, 'getRawOriginal')) {
            return $this->order->getRawOriginal($key);
        }

        return $this->order->getAttributes()[$key] ?? null;
    }

    /**
     * Get customer email from order.
     * 
     * Tries to get email from user relationship first, then falls back to billing address.
     *
     * @return string|null
     */
    protected function getCustomerEmail(): ?string
    {
        // Try to get email from customer's user account
        if (is_object($this->order->customer) && $this->order->customer->user) {
            return $this->order->customer->user->email;
        }

        // Fallback to billing address email
        if (is_array($this->order->billing_address) && isset($this->order->billing_address['email'])) {
            return $this->order->billing_address['email'];
        }

        return null;
    }
}
