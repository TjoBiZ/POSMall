<?php

namespace KodZero\POSMall\Classes\Payments\Webhooks;

use Illuminate\Http\Response;
use KodZero\POSMall\Classes\Payments\PaymentResult;
use KodZero\POSMall\Classes\Payments\SuccessfulPaymentFinalizer;
use KodZero\POSMall\Classes\Payments\StripeHostedCheckout;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentGatewaySettings;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;

/**
 * Handles Stripe Checkout webhook events.
 * 
 * This serves as a backup payment verification mechanism in case the user
 * doesn't complete the browser redirect after payment.
 */
class StripeHostedCheckoutWebhook
{
    /**
     * Handle incoming webhook from Stripe.
     *
     * @return Response
     */
    public function handle(): Response
    {
        try {
            $payload = @file_get_contents('php://input');
            $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
            $webhookSecret = $this->getWebhookSecret();
            
            if (!$webhookSecret) {
                logger()->error('POSMall: Stripe webhook secret not configured');
                return response('Webhook secret not configured', 500);
            }
            
            // Verify webhook signature
            try {
                $event = Webhook::constructEvent(
                    $payload,
                    $sigHeader,
                    $webhookSecret
                );
            } catch (SignatureVerificationException $e) {
                logger()->error('POSMall: Stripe webhook signature verification failed', [
                    'error' => $e->getMessage()
                ]);
                return response('Invalid signature', 400);
            }
            
            // Handle the event
            if ($event->type === 'checkout.session.completed') {
                $this->handleCheckoutSessionCompleted($event->data->object);
            }
            
            return response('Webhook handled', 200);
            
        } catch (Throwable $e) {
            $context = [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ];

            if (config('app.debug') || app()->environment(['local', 'testing'])) {
                $context['trace'] = $e->getTraceAsString();
            }

            logger()->error('POSMall: Stripe webhook error', $context);
            
            return response('Webhook error', 500);
        }
    }

    /**
     * Handle checkout.session.completed event.
     *
     * @param object $session Stripe Session object
     * @return void
     */
    protected function handleCheckoutSessionCompleted($session): void
    {
        // Only process paid sessions
        if ($session->payment_status !== 'paid') {
            logger()->info('POSMall: Stripe session not paid yet', [
                'session_id' => $session->id,
                'payment_status' => $session->payment_status,
            ]);
            return;
        }
        
        // Get order ID from metadata or client_reference_id
        $orderId = $session->metadata->order_id ?? $session->client_reference_id;
        
        if (!$orderId) {
            logger()->error('POSMall: No order ID in Stripe webhook', [
                'session_id' => $session->id,
            ]);
            return;
        }
        
        // Find the order
        $order = Order::find($orderId);
        
        if (!$order) {
            logger()->error('POSMall: Order not found in Stripe webhook', [
                'order_id' => $orderId,
                'session_id' => $session->id,
            ]);
            return;
        }

        $paymentProvider = new StripeHostedCheckout($order);
        $paymentResult = new PaymentResult($paymentProvider, $order);
        $paymentData = $paymentProvider->sessionPaymentData($session);

        if (! $paymentProvider->sessionMatchesOrder($session)) {
            logger()->error('POSMall: Stripe webhook session does not match order', [
                'order_id' => $orderId,
                'session_id' => $session->id,
            ]);

            return;
        }
        
        // Check if order is already paid to avoid duplicate processing
        if ($order->payment_state === PaidState::class) {
            (new SuccessfulPaymentFinalizer())->finalize($paymentResult);

            logger()->info('POSMall: Order already marked as paid', [
                'order_id' => $orderId,
                'session_id' => $session->id,
            ]);
            return;
        }
        
        try {
            // Mark order as paid using PaymentResult success method
            $paymentResult->success($paymentData, $session);
            (new SuccessfulPaymentFinalizer())->finalize($paymentResult);
            
            logger()->info('POSMall: Order marked as paid via webhook', [
                'order_id' => $orderId,
                'session_id' => $session->id,
            ]);
            
        } catch (Throwable $e) {
            logger()->critical('POSMall: Failed to mark order as paid via webhook', [
                'order_id' => $orderId,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the webhook secret from settings.
     *
     * @return string|null
     */
    protected function getWebhookSecret(): ?string
    {
        $secret = PaymentGatewaySettings::get('stripe_webhook_secret');
        
        if (!$secret) {
            return null;
        }
        
        return PaymentGatewaySettings::normalizeSecret($secret);
    }
}
