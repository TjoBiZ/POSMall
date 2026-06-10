<?php

namespace KodZero\POSMall\Classes\Payments;

use Config;
use DB;
use KodZero\POSMall\Classes\PaymentState\FailedState;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Classes\PaymentState\PendingState;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentLog;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The PaymentResult contains the result of a payment attempt.
 */
class PaymentResult
{
    /**
     * If the payment was successful.
     * @var bool
     */
    public $successful = false;

    /**
     * If this payment needs a redirect.
     * @var bool
     */
    public $redirect = false;

    /**
     * Use this response as redirect.
     * @var \Illuminate\Http\Response
     */
    public $redirectResponse;

    /**
     * Redirect the user to this URL.
     * @var string
     */
    public $redirectUrl = '';

    /**
     * The failed payment log.
     * @var PaymentLog
     */
    public $failedPayment;

    /**
     * The order that is being processed.
     * @var Order
     */
    public $order;

    /**
     * Error message in case of a failure.
     * @var string
     */
    public $message;

    /**
     * The used PaymentProvider for this payment.
     * @var PaymentProvider
     */
    public $provider;

    /**
     * PaymentResult constructor.
     *
     * @param PaymentProvider $provider
     * @param Order $order
     */
    public function __construct(PaymentProvider $provider, Order $order)
    {
        $this->provider   = $provider;
        $this->order      = $order;
        $this->successful = false;
    }

    /**
     * The payment was successful.
     *
     * The payment is logged, associated with the order
     * and the order is marked as paid.
     *
     * @param array $data
     * @param $response
     *
     * @return PaymentResult
     */
    public function success(array $data, $response): self
    {
        $this->successful = true;

        try {
            DB::transaction(function () use ($data, $response) {
                $this->order = $this->lockOrderForPaymentUpdate();

                if ($this->order->payment_state === PaidState::class) {
                    return;
                }

                $payment = null;

                try {
                    $payment = $this->logSuccessfulPayment($data, $response);
                } catch (Throwable $e) {
                    // Even if the log failed we *have* to mark this order as paid since the payment went already through.
                    logger()->error(
                        'POSMall: Could not log successful payment.',
                        $this->logContext($data, $response, $e)
                    );
                }

                if ($payment) {
                    $this->order->payment_id = $payment->id;
                }

                $this->order->payment_state = PaidState::class;
                $this->order->save();
            });
        } catch (Throwable $e) {
            // If the order could not be marked as paid the shop admin will have to do this manually.
            logger()->critical(
                'POSMall: Could not mark paid order as paid.',
                $this->logContext($data, $response, $e)
            );
        }

        return $this;
    }

    /**
     * The payment is pending.
     *
     * No payment is logged. The order's payment state
     * is marked as pending.
     *
     * @return PaymentResult
     */
    public function pending(): self
    {
        $this->successful = true;

        try {
            DB::transaction(function () {
                $this->order = $this->lockOrderForPaymentUpdate();

                if ($this->order->payment_state === PaidState::class) {
                    return;
                }

                $this->order->payment_state = PendingState::class;
                $this->order->save();
            });
        } catch (Throwable $e) {
            // If the order could not be marked as pending the shop admin will have to do this manually.
            logger()->critical(
                'POSMall: Could not mark pending order as pending.',
                ['order' => $this->order, 'exception' => $e]
            );
        }

        return $this;
    }

    /**
     * The payment has failed.
     *
     * Unless disabled in the config, the failed payment will be logged.
     * The order's payment state is always marked as failed.
     *
     * @param array $data
     * @param mixed $response
     *
     * @return self
     */
    public function fail(array $data, $response): self
    {
        $this->successful = false;

        $shouldLog = Config::get('kodzero.posmall::features.log_failed_payments', true);

        if ($shouldLog) {
            logger()->error(
                'POSMall: A payment failed.',
                $this->logContext($data, $response)
            );
        }

        try {
            DB::transaction(function () use ($data, $response, $shouldLog) {
                $this->order = $this->lockOrderForPaymentUpdate();

                if ($this->order->payment_state === PaidState::class) {
                    $this->successful = true;

                    return;
                }

                try {
                    $this->failedPayment = $this->logFailedPayment($data, $response);
                } catch (Throwable $e) {
                    if ($shouldLog) {
                        logger()->error(
                            'POSMall: Could not log failed payment.',
                            $this->logContext($data, $response, $e)
                        );
                    }
                }

                $this->order->payment_state = FailedState::class;
                $this->order->save();
            });
        } catch (Throwable $e) {
            if ($shouldLog) {
                logger()->critical(
                    'POSMall: Could not mark failed order as failed.',
                    $this->logContext($data, $response, $e)
                );
            }
        }

        return $this;
    }

    /**
     * The payment requires a redirect to an external URL.
     *
     * @param $url
     *
     * @return PaymentResult
     */
    public function redirect($url): self
    {
        $this->redirect    = true;
        $this->redirectUrl = $url;

        return $this;
    }

    /**
     * The payment gateway returned a re-usable Symfony response.
     *
     * @param Response $response
     *
     * @return PaymentResult
     */
    public function redirectResponse(Response $response): self
    {
        $this->redirect         = true;
        $this->redirectResponse = $response;

        return $this;
    }

    /**
     * Create a PaymentLog entry for a failed payment.
     *
     * @param array $data
     * @param $response
     *
     * @return PaymentLog
     */
    protected function logFailedPayment(array $data, $response): PaymentLog
    {
        return $this->logPayment(true, $data, $response);
    }

    /**
     * Create a PaymentLog entry for a successful payment.
     *
     * @param array $data
     * @param $response
     *
     * @return PaymentLog
     */
    protected function logSuccessfulPayment(array $data, $response): PaymentLog
    {
        return $this->logPayment(false, $data, $response);
    }

    protected function lockOrderForPaymentUpdate(): Order
    {
        return Order::whereKey($this->order->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Create a PaymentLog entry.
     *
     * @param bool $failed
     * @param array $data
     * @param $response
     *
     * @return PaymentLog
     */
    protected function logPayment(bool $failed, array $data, $response): PaymentLog
    {
        $log                   = new PaymentLog();
        $log->failed           = $failed;
        $log->data             = $this->sanitizePaymentPayload($data);
        $log->ip               = request()->ip();
        $log->session_id       = session()->get('cart_session_id');
        $log->payment_provider = $this->provider->identifier();
        $log->payment_method   = $this->order->payment_method;
        $log->order_data       = $this->order;
        $log->order_id         = $this->order->id;

        if ($response) {
            $log->message = $this->safePaymentMessage($response);

            $log->code = method_exists($response, 'getCode')
                ? $response->getCode()
                : null;
        }

        return tap($log)->save();
    }

    protected function logContext(array $data, $response, ?Throwable $exception = null): array
    {
        $context = [
            'data' => $this->sanitizePaymentPayload($data),
            'response' => $this->safePaymentResponse($response),
            'order_id' => optional($this->order)->id,
        ];

        if ($exception) {
            $context['exception'] = $exception;
        }

        return $context;
    }

    protected function sanitizePaymentPayload(array $payload): array
    {
        $sensitive = '/token|secret|password|client_secret|source|payment_method|payer_id|card|authorization/i';

        return collect($payload)->mapWithKeys(function ($value, $key) use ($sensitive) {
            if (preg_match($sensitive, (string)$key)) {
                return [$key => '[redacted]'];
            }

            return [$key => is_array($value) ? $this->sanitizePaymentPayload($value) : $value];
        })->all();
    }

    protected function safePaymentResponse($response)
    {
        if (! $response) {
            return null;
        }

        if (is_array($response)) {
            return $this->sanitizePaymentPayload($response);
        }

        if (is_scalar($response)) {
            return $this->redactSensitiveString((string)$response);
        }

        if (method_exists($response, 'getMessage')) {
            return [
                'message' => $this->redactSensitiveString((string)$response->getMessage()),
                'code' => method_exists($response, 'getCode') ? $response->getCode() : null,
            ];
        }

        return ['class' => get_class($response)];
    }

    protected function safePaymentMessage($response): string
    {
        if (method_exists($response, 'getMessage')) {
            return $this->redactSensitiveString((string)$response->getMessage());
        }

        return (string)json_encode($this->safePaymentResponse($response));
    }

    protected function redactSensitiveString(string $value): string
    {
        $value = preg_replace('/\b(?:tok|sk|pk|src|pm|pi|cs|cus)_[A-Za-z0-9_=-]+/i', '[redacted]', $value);

        return preg_replace(
            '/\b(token|secret|password|client_secret|source|payment_method|payer_id|card|authorization)\s*[:=]\s*[^,\s]+/i',
            '$1=[redacted]',
            (string)$value
        );
    }
}
