<?php

namespace KodZero\POSMall\Classes\Payments;

use KodZero\POSMall\Models\PaymentGatewaySettings;
use Omnipay\Omnipay;
use Request;
use RuntimeException;
use Session;
use Throwable;

/**
 * Process the payment via PayPal's REST API.
 */
class PayPalRest extends PaymentProvider
{
    private const CONFIG_ERROR_MESSAGE = 'PayPal REST credentials are not configured correctly. Re-enter the client ID and secret; they must be different.';

    /**
     * {@inheritdoc}
     */
    public function name(): string
    {
        return 'PayPal Rest API';
    }

    /**
     * {@inheritdoc}
     */
    public function identifier(): string
    {
        return 'paypal-rest';
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
        if (! $this->hasConfiguredCredentials()) {
            return $result->fail(
                ['message' => self::CONFIG_ERROR_MESSAGE],
                new RuntimeException(self::CONFIG_ERROR_MESSAGE)
            );
        }

        $gateway = $this->getGateway();

        $response = null;

        try {
            $response = $gateway->purchase([
                'amount'    => $this->order->total_in_currency,
                'currency'  => $this->order->currency['code'],
                'returnUrl' => $this->returnUrl(),
                'cancelUrl' => $this->cancelUrl(),
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        // PayPal has to return a RedirectResponse if everything went well
        if (! $response->isRedirect()) {
            return $result->fail((array)$response->getData(), $response);
        }

        Session::put('posmall.payment.callback', self::class);
        Session::put('posmall.paypal.transactionReference', $response->getTransactionReference());

        return $result->redirect($response->getRedirectResponse()->getTargetUrl());
    }

    /**
     * PayPal has processed the payment and redirected the user back.
     *
     * @param PaymentResult $result
     *
     * @return PaymentResult
     */
    public function complete(PaymentResult $result): PaymentResult
    {
        $key     = Session::pull('posmall.paypal.transactionReference');
        $payerId = Request::input('PayerID');

        if (! $key || ! $payerId) {
            return $result->fail([
                'msg'   => 'Missing payment data',
                'key'   => $key,
                'payer' => $payerId,
            ], null);
        }

        $this->setOrder($result->order);

        try {
            $response = $this->getGateway()->completePurchase([
                'transactionReference' => $key,
                'payerId'              => $payerId,
            ])->send();
        } catch (Throwable $e) {
            return $result->fail([], $e);
        }

        $data = (array)$response->getData();

        if (! $response->isSuccessful()) {
            return $result->fail($data, $response);
        }

        return $result->success($data, $response);
    }

    /**
     * {@inheritdoc}
     */
    public function settings(): array
    {
        return [
            'paypal_test_mode' => [
                'label'   => 'kodzero.posmall::lang.payment_gateway_settings.paypal.test_mode',
                'comment' => 'kodzero.posmall::lang.payment_gateway_settings.paypal.test_mode_comment',
                'span'    => 'left',
                'type'    => 'switch',
            ],
            'paypal_client_id' => [
                'label' => 'kodzero.posmall::lang.payment_gateway_settings.paypal.client_id',
                'span'  => 'left',
                'type'  => 'text',
            ],
            'paypal_secret'    => [
                'label' => 'kodzero.posmall::lang.payment_gateway_settings.paypal.secret',
                'span'  => 'left',
                'type'  => 'text',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function encryptedSettings(): array
    {
        return ['paypal_client_id', 'paypal_secret'];
    }

    /**
     * Build the Omnipay Gateway for PayPal.
     *
     * @return \Omnipay\Common\GatewayInterface
     */
    protected function getGateway()
    {
        $credentials = $this->configuredCredentials();
        if (! $credentials) {
            throw new RuntimeException(self::CONFIG_ERROR_MESSAGE);
        }

        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->initialize([
            'clientId' => $credentials['clientId'],
            'secret'   => $credentials['secret'],
            'testMode' => (bool)PaymentGatewaySettings::get('paypal_test_mode'),
        ]);

        return $gateway;
    }

    protected function hasConfiguredCredentials(): bool
    {
        return $this->configuredCredentials() !== null;
    }

    protected function configuredCredentials(): ?array
    {
        $clientId = $this->decryptCredential('paypal_client_id');
        $secret   = $this->decryptCredential('paypal_secret');

        if ($clientId === '' || $secret === '' || hash_equals($clientId, $secret)) {
            return null;
        }

        return ['clientId' => $clientId, 'secret' => $secret];
    }

    protected function decryptCredential(string $key): string
    {
        return PaymentGatewaySettings::secret($key);
    }
}
