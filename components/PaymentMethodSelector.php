<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Illuminate\Support\Collection;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\PaymentState\FailedState;
use KodZero\POSMall\Classes\PaymentState\PendingState;
use KodZero\POSMall\Classes\Payments\CheckoutPaymentDataStore;
use KodZero\POSMall\Classes\Payments\PaymentGateway;
use KodZero\POSMall\Classes\Payments\PaymentRedirector;
use KodZero\POSMall\Classes\Payments\PaymentService;
use KodZero\POSMall\Classes\Traits\AddressZipSuggestions;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CustomerPaymentMethod;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentMethod;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Validator;

/**
 * The PaymentMethodSelector component allows the user
 * to select a payment method during checkout.
 */
class PaymentMethodSelector extends POSMallComponent
{
    use HashIds;
    use AddressZipSuggestions;

    /**
     * The user's cart.
     *
     * @var Cart
     */
    public $cart;

    /**
     * The active payment method.
     *
     * @var PaymentMethod
     */
    public $activeMethod;

    /**
     * Payment data.
     *
     * @var Collection
     */
    public $paymentData;

    /**
     * All available PaymentMethods
     * @var Collection
     */
    public $methods;

    /**
     * All available CustomerPaymentMethods
     * @var Collection
     */
    public $customerMethods;

    /**
     * The current order.
     *
     * @var Order
     */
    public $order;

    /**
     * Depending on whether the order is paid during checkout
     * or later on the component is working on either the Order
     * or the Cart model.
     *
     * @var Order|Cart
     */
    public $workingOnModel;

    /**
     * Backend setting whether shipping should be before payment.
     *
     * @var bool
     */
    public $shippingSelectionBeforePayment = false;

    /**
     * Whether the current existing order can be paid.
     *
     * @var bool
     */
    public $canPayOrder = true;

    /**
     * Clean message for non-payable existing orders.
     *
     * @var string|null
     */
    public $paymentUnavailableMessage;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.paymentMethodSelector.details.name',
            'description' => 'kodzero.posmall::lang.components.paymentMethodSelector.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        return [];
    }

    public function init()
    {
        $this->addJs('assets/address-zip-suggestions.js');
        $this->prepareAddressAutocompleteSettings();
    }

    /**
     * The component is executed.
     *
     * @return string|void
     */
    public function onRun()
    {
        if ($type = request()->input('return')) {
            return (new PaymentRedirector($this->paymentResultPage()))->handleOffSiteReturn($type);
        }

        return $this->setData();
    }

    /**
     * The user has selected a payment method.
     *
     * Any specified payment data is stored in the session.
     *
     * @throws \Cms\Classes\CmsException
     * @return Response
     */
    public function onSubmit()
    {
        $this->setData();
        $this->guardPayableOrder();

        $data = post('payment_data', []);
        $method = $this->getPaymentMethod();

        // Create the payment gateway to trigger the validation.
        // If not all specified data is valid an exception is thrown here.
        $gateway = app(PaymentGateway::class);
        $gateway->init($method, $data);

        // When the user hits "submit" no customer payment method was selected
        // so make sure to remove the information for the cart or order
        // in case it sits there from a previous payment attempt.
        $this->workingOnModel->customer_payment_method_id = null;
        $this->workingOnModel->save();

        return $this->doRedirect($gateway, $method, $data);
    }

    /**
     * A different payment method has been selected.
     *
     * @throws ValidationException
     * @return array
     */
    public function onChangeMethod()
    {
        $this->setData();
        $this->guardPayableOrder();

        $rules = [
            'id' => 'bail|required|integer|exists:kodzero_posmall_payment_methods,id',
        ];

        $validation = Validator::make(post() ?: [], $rules);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $id = post('id');

        if (! $this->methods || ! $this->methods->contains('id', (int)$id)) {
            throw new ValidationException([
                'id' => trans('kodzero.posmall::lang.components.paymentMethodSelector.errors.unavailable'),
            ]);
        }

        $this->workingOnModel->payment_method_id = $id;
        $this->workingOnModel->customer_payment_method_id = null;
        $this->workingOnModel->save();

        if ($this->workingOnModel instanceof Cart) {
            app(CheckoutPaymentDataStore::class)->forgetForCart($this->workingOnModel);
        }

        $this->setData();

        return [
            '.mall-payment-method-selector' => $this->renderPartial($this->alias . '::selector'),
            'method'                        => PaymentMethod::where('id', $id)->first(),
        ];
    }

    /**
     * The customer proceeds with a saved payment method.
     */
    public function onUseCustomerPaymentMethod()
    {
        $this->setData();
        $this->guardPayableOrder();

        $id = $this->decode(post('id'));

        $method = CustomerPaymentMethod::where('customer_id', $this->workingOnModel->customer->id)
            ->where('id', $id)->first();

        if (! $method) {
            throw new ValidationException([
                'customer_method' => trans('customer_payment_method.does_not_exist'),
            ]);
        }

        $this->workingOnModel->payment_method_id          = $method->payment_method_id;
        $this->workingOnModel->customer_payment_method_id = $method->id;
        $this->workingOnModel->save();

        $data = ['use_customer_payment_method' => true];

        $gateway = app(PaymentGateway::class);
        $method = $this->getPaymentMethod();
        $gateway->init($method, $data);

        return $this->doRedirect($gateway, $method, $data);
    }

    /**
     * Renders the payment form of the currently selected
     * payment method.
     *
     * @return string
     */
    public function renderPaymentForm()
    {
        if (! $this->workingOnModel->payment_method) {
            return '';
        }

        /** @var PaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        try {
            return $gateway
                ->getProviderById($this->workingOnModel->payment_method->payment_provider)
                ->renderPaymentForm($this->workingOnModel, [
                    'addressZipHandler' => $this->alias . '::onSuggestAddressZip',
                ]);
        } catch (Throwable $e) {
            return sprintf(
                '<div class="alert alert-warning mb-0">%s</div>',
                e(trans('kodzero.posmall::frontend.payment_method.unavailable'))
            );
        }
    }

    public function isManualPaymentMethod(PaymentMethod $method): bool
    {
        return $method->payment_provider === 'offline';
    }

    /**
     * This method sets all variables needed for this component to work.
     *
     * @return void
     */
    protected function setData()
    {
        $this->setVar('canPayOrder', true);
        $this->setVar('paymentUnavailableMessage', null);

        $user = Auth::user();

        if (! $user) {
            if (! $this->setPaymentLinkOrder()) {
                return;
            }
        } else {
            if (! $this->ensureCustomerForUser($user)) {
                return;
            }

            $this->setVar('cart', Cart::byUser($user));
            $this->workingOnModel = $this->cart;

            if ($orderHash = $this->orderHash()) {
                $this->setExistingOrder($user, $orderHash);
            }
        }

        if ($this->order && ! $this->isOrderPayable($this->order)) {
            $this->setOrderUnavailable();
        }

        $methods = $this->canPayOrder
            ? $this->availablePaymentMethods()
            : collect([]);

        if ($this->order && $this->canPayOrder && $methods->isEmpty()) {
            $this->setOrderUnavailable();
            $methods = collect([]);
        }

        $paymentMethodId = $this->order->payment_method_id ?? $this->cart->payment_method_id;
        $method = $methods->firstWhere('id', (int)$paymentMethodId);

        $this->setVar('methods', $methods);
        $this->setVar('customerMethods', $this->getCustomerMethods());
        $this->setVar('activeMethod', $method);
        $this->shippingSelectionBeforePayment = (bool)GeneralSettings::get('shipping_selection_before_payment', false);
        $this->setVar('shippingSelectionBeforePayment', $this->shippingSelectionBeforePayment);	// Needed by themes

        $paymentData = $this->workingOnModel instanceof Cart
            ? app(CheckoutPaymentDataStore::class)->getForCart($this->workingOnModel, $method)
            : [];

        $this->setVar('paymentData', $paymentData);
    }

    protected function setPaymentLinkOrder(): bool
    {
        $sessionHash = session()->get('posmall.processing_order.id');
        $requestHash = $this->orderHash();

        if (!$sessionHash || !$requestHash || !hash_equals((string)$sessionHash, (string)$requestHash)) {
            $this->setOrderUnavailable();

            return false;
        }

        $orderId = $this->decode((string)$requestHash);

        if (!$orderId) {
            $this->setOrderUnavailable();

            return false;
        }

        $order = Order::where('id', $orderId)
            ->whereNotNull('payment_link_token_hash')
            ->where('payment_link_expires_at', '>', now())
            ->first();

        if (!$order) {
            $this->setOrderUnavailable();

            return false;
        }

        $order->loadMissing(['customer.payment_methods', 'order_state', 'payment_method', 'products']);
        $this->setVar('order', $order);
        $this->workingOnModel = $order;

        return true;
    }

    /**
     * Get the URL to a specific checkout step.
     *
     * @param $step
     * @param null $via
     *
     * @return string
     */
    protected function getStepUrl($step, $via = null): string
    {
        $url = $this->controller->pageUrl($this->page->page->fileName, ['step' => $step]);

        if (! $via) {
            return $url;
        }

        return $url . '?' . http_build_query(['via' => $via]);
    }

    /**
     * Return all CustomerPaymentMethods grouped
     * by the payment method.
     *
     * @return Collection
     */
    protected function getCustomerMethods()
    {
        if (! $this->workingOnModel || ! $this->workingOnModel->customer) {
            return collect([]);
        }

        return optional($this->workingOnModel->customer->payment_methods)->groupBy('payment_method_id');
    }

    /**
     * @param PaymentGateway $gateway
     * @param $data
     *
     * @throws \Cms\Classes\CmsException
     * @return Response|array
     */
    protected function doRedirect(PaymentGateway $gateway, PaymentMethod $method, $data)
    {
        // If an order is already available, this is not the normal checkout flow but a
        // subsequent try to pay for an existing order for which the payment failed.
        if ($this->order) {
            // In case the order already exists the payment can be executed directly.
            $paymentService = new PaymentService(
                $gateway,
                $this->order,
                $this->paymentResultPage()
            );

            return $paymentService->process('payment');
        }

        // Store only provider-safe data such as Stripe tokens, scoped to this checkout cart.
        app(CheckoutPaymentDataStore::class)->rememberForCart($this->workingOnModel, $method, $data);

        $nextStep = 'confirm';

        if (! $this->shippingSelectionBeforePayment) {
            $nextStep = request()->get('via') === 'confirm' ? 'confirm' : 'shipping';
        }

        $url = $this->getStepUrl($nextStep, 'payment');

        // If the analytics component is present return the datalayer partial that handles the redirect.
        if ($this->page->layout->hasComponent('enhancedEcommerceAnalytics')) {
            return [
                '#mall-datalayer' => $this->renderPartial($this->alias . '::datalayer', ['url' => $url]),
            ];
        }

        return redirect()->to($url);
    }

    /**
     * @return mixed
     */
    protected function getPaymentMethod()
    {
        $method = PaymentMethod::where('id', $this->workingOnModel->payment_method_id)->first();

        if (! $method || ! $this->methods->contains('id', $method->id)) {
            throw new ValidationException([
                'id' => trans('kodzero.posmall::lang.components.paymentMethodSelector.errors.unavailable'),
            ]);
        }

        return $method;
    }

    protected function orderHash(): ?string
    {
        $hash = request()->get('order') ?: $this->param('hash');

        return $hash ? (string)$hash : null;
    }

    protected function setExistingOrder($user, string $hash): void
    {
        $orderId = $this->decode($hash);
        $customer = $this->ensureCustomerForUser($user);

        if (! $orderId || ! $customer) {
            $this->setOrderUnavailable();

            return;
        }

        $order = Order::byCustomer($customer)->where('id', $orderId)->first();

        if (! $order) {
            $this->setOrderUnavailable();

            return;
        }

        $order->loadMissing(['customer.payment_methods', 'order_state', 'payment_method', 'products']);
        $this->setVar('order', $order);
        $this->workingOnModel = $order;
    }

    protected function isOrderPayable(Order $order): bool
    {
        if ($order->is_paid || ($order->order_state && $order->is_cancelled)) {
            return false;
        }

        return in_array($order->payment_state, [
            PendingState::class,
            FailedState::class,
        ], true);
    }

    protected function setOrderUnavailable(): void
    {
        $this->setVar('canPayOrder', false);
        $this->setVar(
            'paymentUnavailableMessage',
            trans('kodzero.posmall::frontend.payment_method.order_not_payable')
        );
    }

    protected function guardPayableOrder(): void
    {
        if ($this->order && ! $this->canPayOrder) {
            throw new ValidationException([
                'order' => $this->paymentUnavailableMessage,
            ]);
        }
    }

    protected function availablePaymentMethods(): Collection
    {
        $methods = $this->order
            ? PaymentMethod::orderBy('sort_order', 'ASC')->get()
            : PaymentMethod::getAvailableByCart($this->cart);

        $providers = app(PaymentGateway::class)->getProviders();

        return $methods
            ->filter(fn (PaymentMethod $method) => isset($providers[$method->payment_provider]))
            ->values();
    }

    protected function paymentResultPage(): string
    {
        return GeneralSettings::get('checkout_page') ?: $this->page->page->fileName;
    }
}
