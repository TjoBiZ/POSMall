<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Illuminate\Support\Str;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Payments\CheckoutPaymentDataStore;
use KodZero\POSMall\Classes\Payments\PaymentGateway;
use KodZero\POSMall\Classes\Payments\PaymentRedirector;
use KodZero\POSMall\Classes\Payments\PaymentService;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Classes\Taxes\UsaTaxCheckoutGuard;
use KodZero\POSMall\Components\Cart as CartComponent;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentLog;
use KodZero\POSMall\Models\PaymentMethod;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The Checkout component orchestrates the checkout process.
 */
class Checkout extends POSMallComponent
{
    /**
     * The user's cart.
     * @var Cart
     */
    public $cart;

    /**
     * The error massage received from the PaymentProvider.
     * @var string
     */
    public $paymentError;

    /**
     * The currently active step.
     * @var string
     */
    public $step;

    /**
     * Show the notes field.
     *
     * @var bool
     */
    public $showNotesField = false;

    /**
     * The order that was created during checkout.
     * @var Order
     */
    public $order;

    /**
     * The name of the my account page.
     * @var string
     */
    public $accountPage;

    /**
     * Google Tag Manager dataLayer code.
     * @var array
     */
    public $dataLayer;

    /**
     * The selected payment method.
     * @var PaymentMethod
     */
    public $paymentMethod;

    /**
     * Backend setting whether shipping should be before payment.
     * @var bool
     */
    public $shippingSelectionBeforePayment = false;

    /**
     * Component details.
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.checkout.details.name',
            'description' => 'kodzero.posmall::lang.components.checkout.details.description',
        ];
    }

    /**
     * Properties of this component.
     * @return array
     */
    public function defineProperties()
    {
        return [
            'step' => [
                'type' => 'dropdown',
                'name' => 'kodzero.posmall::lang.components.checkout.properties.step.name',
            ],
            'showNotesField' => [
                'name' => 'kodzero.posmall::lang.components.checkout.properties.showNotesField.name',
                'description' => 'kodzero.posmall::lang.components.checkout.properties.showNotesField.description',
                'type' => 'checkbox',
                'default' => false,
            ],
        ];
    }

    /**
     * Options array for the step dropdown.
     * @return array
     */
    public function getStepOptions()
    {
        return [
            'payment'   => trans('kodzero.posmall::lang.components.checkout.steps.payment'),
            'shipping'  => trans('kodzero.posmall::lang.components.checkout.steps.shipping'),
            'confirm'   => trans('kodzero.posmall::lang.components.checkout.steps.confirm'),
            'failed'    => trans('kodzero.posmall::lang.components.checkout.steps.failed'),
            'cancelled' => trans('kodzero.posmall::lang.components.checkout.steps.cancelled'),
            'done'      => trans('kodzero.posmall::lang.components.checkout.steps.done'),
        ];
    }

    /**
     * The component is initialized.
     * All child components get added.
     * @return void
     */
    public function init()
    {
        $this->showNotesField = (bool)$this->property('showNotesField');
        $step = $this->currentStep();

        $this->addComponent(CartComponent::class, 'cart', ['showDiscountApplier' => false]);

        if ($step === 'confirm') {
            $this->addComponent(AddressSelector::class, 'billingAddressSelector', ['type' => 'billing']);
            $this->addComponent(AddressSelector::class, 'shippingAddressSelector', ['type' => 'shipping']);
        }

        if ($step === 'shipping') {
            $this->addComponent(
                ShippingMethodSelector::class,
                'shippingMethodSelector',
                ['skipIfOnlyOneAvailable' => false]
            );
        }

        if ($step === 'payment') {
            $this->addComponent(PaymentMethodSelector::class, 'paymentMethodSelector', []);
        }

        $this->setData();
    }

    /**
     * The component is executed.
     * @throws \Cms\Classes\CmsException
     * @return RedirectResponse|void
     */
    public function onRun()
    {
        // An off-site payment has been completed
        if ($type = request()->input('return')) {
            return $this->handleOffSiteReturn($type);
        }

        // If no step is provided or the step is invalid, redirect the user to
        // the payment method selection screen.
        $step = $this->currentStep();

        if (! $step || ! array_key_exists($step, $this->getStepOptions())) {
            $url = $this->stepUrl($this->shippingSelectionBeforePayment ? 'shipping' : 'payment');

            return redirect()->to($url);
        }

        if ($step === 'confirm' && $this->cart && $this->cart->products->count() < 1) {
            if ($order = $this->processingOrderFromSession()) {
                $targetStep = $order->is_paid ? 'done' : 'failed';

                return redirect()->to($this->stepUrl($targetStep, [
                    'order' => $order->hashId,
                    'flow' => session()->get('posmall.checkout.flow', 'checkout'),
                ]));
            }
        }

        if ($step === 'failed' && ! $this->order && ! request()->has('order')) {
            if ($order = $this->processingOrderFromSession()) {
                return redirect()->to($this->stepUrl('failed', [
                    'order' => $order->hashId,
                    'flow' => session()->get('posmall.checkout.flow', 'checkout'),
                ]));
            }
        }

        // If an order has been created but something failed we can fetch the paymentError
        // from the order's payment logs.
        if ($step === 'failed' && $this->order) {
            $this->paymentError = $this->getFailedPaymentMessage();
        }
    }

    public function getFailedPaymentLog(): ?PaymentLog
    {
        if (! $this->order) {
            return null;
        }

        $logs = $this->order->payment_logs;

        return $logs->firstWhere('failed', true) ?: $logs->first();
    }

    public function getFailedPaymentMessage(): ?string
    {
        $message = optional($this->getFailedPaymentLog())->message;

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message);
        }

        $message = trim((string)$message);

        if ($message === '') {
            return null;
        }

        $message = strip_tags($message);
        $message = preg_replace('/\s+/', ' ', $message) ?? '';
        $message = preg_replace('/\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9_]+/', '[redacted]', $message) ?? '';

        $provider = trim((string)optional($this->getFailedPaymentLog())->payment_provider);
        if ($provider === 'paypal-rest' && preg_match('/authentication|authorization|credential/i', $message)) {
            return trans('kodzero.posmall::frontend.payment_method.paypal_unavailable');
        }

        if ($provider === 'stripe' && preg_match('/real card.*testing|testing.*real card/i', $message)) {
            return trans('kodzero.posmall::frontend.payment_method.stripe_test_card_required');
        }

        return Str::limit($message, 180);
    }

    public function getFailedPaymentProviderLabel(): ?string
    {
        $provider = trim((string)optional($this->getFailedPaymentLog())->payment_provider);

        if ($provider === '') {
            $provider = trim((string)optional(optional($this->order)->payment_method)->payment_provider);
        }

        if ($provider === '') {
            return null;
        }

        return Str::title(str_replace(['-', '_'], ' ', $provider));
    }

    /**
     * Handle the checkout process.
     * @throws ValidationException
     * @throws \Cms\Classes\CmsException
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function onCheckout()
    {
        $this->setData();

        if (! $this->cart) {
            throw new ValidationException([
                'cart' => 'Your checkout session is missing. Return to the cart and start checkout again.',
            ]);
        }

        if ($this->cart->payment_method_id === null) {
            throw new ValidationException(
                [trans('kodzero.posmall::lang.components.checkout.errors.missing_settings')]
            );
        }

        $paymentDataStore = app(CheckoutPaymentDataStore::class);
        $paymentMethod = PaymentMethod::where('id', $this->cart->payment_method_id)->firstOrFail();
        $paymentData = $paymentDataStore->getForCart($this->cart, $paymentMethod);

        if (
            $paymentMethod->payment_provider === 'stripe'
            && empty($this->cart->customer_payment_method_id)
            && empty($paymentData['token'])
        ) {
            throw new ValidationException([
                'payment' => 'Card payment details are missing. Return to Payment and enter the card details again before placing the order.',
            ]);
        }
        
        // Grab the PaymentGateway from the Service Container.
        $gateway = app(PaymentGateway::class);
        $gateway->init($paymentMethod, $paymentData);

        $attributes = [];

        if ($this->showNotesField) {
            $attributes['customer_notes'] = Str::limit(strip_tags((string)post('customer_notes', '')), 1000);
        }

        app(UsaTaxCheckoutGuard::class)->validate($this->cart);

        // Create the order first.
        $order = Order::fromCart($this->cart, $attributes);

        // If the order was created successfully proceed with the payment.
        $paymentService = new PaymentService(
            $gateway,
            $order,
            $this->page->page->fileName
        );

        try {
            return $paymentService->process();
        } finally {
            $paymentDataStore->forgetForCart($this->cart);
        }
    }

    /**
     * Return the URL for a specific checkout step.
     *
     * @param $step
     * @param array $params
     *
     * @return string
     */
    public function stepUrl($step, $params = [])
    {
        $url = $this->controller->pageUrl(
            $this->page->page->fileName,
            ['step' => $step]
        );

        if (empty($params)) {
            return $url;
        }

        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . http_build_query($params);
    }

    protected function processingOrderFromSession(): ?Order
    {
        $user = Auth::user();
        $customer = $this->ensureCustomerForUser($user);
        $hash = session()->get('posmall.processing_order.id');
        $orderId = $hash ? $this->decode((string)$hash) : null;

        if ($orderId) {
            $order = Order::where('id', $orderId)
                ->whereHas('products')
                ->first();

            if ($order) {
                return $order;
            }
        }

        $order = Order::where('session_id', session()->getId())
            ->where('payment_state', '!=', PaidState::class)
            ->whereHas('products')
            ->orderByDesc('id')
            ->first();

        if ($order) {
            return $order;
        }

        if (! $user || ! $customer) {
            return $this->recentProcessingOrderFromCurrentIp();
        }

        return Order::byCustomer($customer)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->whereHas('products')
            ->orderByDesc('id')
            ->first()
            ?: $this->recentProcessingOrderFromCurrentIp();
    }

    protected function recentProcessingOrderFromCurrentIp(): ?Order
    {
        $ip = request()->ip();

        if (! $ip) {
            return null;
        }

        return Order::where('ip_address', $ip)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->where('payment_state', '!=', PaidState::class)
            ->whereHas('products')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * This method sets all variables needed for this component to work.
     * @return void
     */
    protected function setData()
    {
        $user = Auth::user();

        if ($order = $this->orderFromRequest($user)) {
            $this->setVar('order', $order);
        }

        if (! $user) {
            return;
        }

        if (! $this->ensureCustomerForUser($user)) {
            return;
        }

        $cart = Cart::byUser($user);

        if (! $cart->payment_method_id) {
            $cart->setPaymentMethod(PaymentMethod::getDefault());
        }
        $this->setVar('cart', $cart);

        $paymentMethod = PaymentMethod::where('id', $cart->payment_method_id)->first();

        if (! $paymentMethod) {
            $paymentMethod = PaymentMethod::getDefault();
            $cart->setPaymentMethod($paymentMethod);
        }

        $this->setVar('paymentMethod', $paymentMethod);
        $this->setVar('step', $this->currentStep());
        $this->setVar('accountPage', GeneralSettings::get('account_page'));
        $this->setVar(
            'shippingSelectionBeforePayment',
            GeneralSettings::get('shipping_selection_before_payment', false)
        );

        $this->setVar('dataLayer', $this->handleDataLayer());
    }

    protected function orderFromRequest($user): ?Order
    {
        $hash = request()->get('order');
        $customer = $this->ensureCustomerForUser($user);

        if (! $hash) {
            return null;
        }

        $orderId = $this->decode((string)$hash);

        if (! $orderId) {
            return null;
        }

        if ($user && $customer) {
            $query = Order::byCustomer($customer)
                ->where('id', $orderId);
        } else {
            $query = Order::where('id', $orderId)
                ->where(function ($query) {
                    $query->where('session_id', session()->getId());

                    if ($ip = request()->ip()) {
                        $query->orWhere('ip_address', $ip);
                    }
                });
        }

        $order = $query->first();

        if ($order) {
            $order->loadMissing(['payment_logs', 'payment_method', 'products.product', 'products.variant']);
        }

        return $order;
    }

    protected function currentStep(): ?string
    {
        $steps = array_keys($this->getStepOptions());

        foreach ([$this->param('step'), $this->property('step'), basename(request()->path())] as $candidate) {
            $step = is_string($candidate) ? trim($candidate) : '';

            if (in_array($step, $steps, true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The user was redirected back to the store from an
     * external payment service.
     *
     * @param string $type
     *
     * @throws \Cms\Classes\CmsException
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function handleOffSiteReturn($type)
    {
        return (new PaymentRedirector($this->page->page->fileName))->handleOffSiteReturn($type);
    }

    protected function getDataLayerProductArray($item)
    {
        $name    = $item->product->name;
        $variant = optional($item->variant)->name;
        $price   = $item->total_post_taxes ?? $item->price()->integer;

        return [
            'id'       => $item->prefixedId,
            'name'     => $name,
            'price'    => (string)round($price / 100, 2),
            'brand'    => optional($item->product->brand)->name ?? array_get($item->brand, 'name'),
            'category' => $item->product->categories->first()->name,
            'variant'  => $variant,
            'quantity' => $item->quantity,
        ];
    }

    protected function getDataLayerCoupon()
    {
        $coupon  = null;
        $coupons = $this->order->discounts ?? [];

        if (count($coupons)) {
            $coupon = implode(',', array_map(fn ($coupon) => array_get($coupon, 'code'), $coupons));
        }

        return $coupon;
    }

    /**
     * Generate Google Tag Manager dataLayer code.
     */
    private function handleDataLayer()
    {
        $isCheckout = request()->get('flow') === 'checkout';

        if (! $this->page->layout->hasComponent('enhancedEcommerceAnalytics')) {
            return;
        }

        $useModel = $this->step === 'done' ? $this->order : $this->cart;
        $data = [
            'event'     => 'checkout',
            'ecommerce' => [
                'products' => $useModel->products->map(fn ($item, $index) => $this->getDataLayerProductArray($item)),
                'checkout' => [
                    'actionField' => [],
                ],
            ],
        ];

        if ($this->step === 'confirm') {
            $data['ecommerce']['checkout']['actionField'] = ['step' => 3];
        }

        if ($this->step === 'done') {
            // The "done" step should only count for the initial Checkout flow, not
            // later payments that are also redirected to this page.
            if ($isCheckout === false) {
                return [];
            }

            unset($data['event'], $data['ecommerce']['checkout']);

            $coupon                        = $this->getDataLayerCoupon();
            $data['ecommerce']['purchase'] = [
                'actionField' => [
                    'id'          => $this->order->hash_id,
                    'affiliation' => 'POSMall',
                    'revenue'     => $this->order->total_post_taxes,
                    'tax'         => $this->order->total_taxes,
                    'shipping'    => $this->order->total_shipping_post_taxes,
                    'coupon'      => $coupon,
                ],
            ];
        }

        return $data;
    }
}
