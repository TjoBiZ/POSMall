<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Customer\SignUpHandler;
use KodZero\POSMall\Classes\Exceptions\OutOfStockException;
use KodZero\POSMall\Classes\Payments\PaymentGateway;
use KodZero\POSMall\Classes\Payments\PaymentRedirector;
use KodZero\POSMall\Classes\Payments\PaymentService;
use KodZero\POSMall\Classes\Taxes\UsaTaxCheckoutGuard;
use KodZero\POSMall\Classes\Traits\AddressZipSuggestions;
use KodZero\POSMall\Classes\User\Auth as FrontendAuth;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CartProduct;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Variant;
use RainLab\Location\Models\Country;
use RainLab\User\Models\User;
use Validator;

/**
 * The QuickCheckout component provides a checkout process on a single page.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class QuickCheckout extends POSMallComponent
{
    use AddressZipSuggestions;

    /**
     * The user's cart.
     *
     * @var Cart
     */
    public $cart;

    /**
     * The currently selected payment method.
     *
     * @var Collection<PaymentMethod>
     */
    public $paymentMethods;

    /**
     * All available CustomerPaymentMethods
     * @var Collection
     */
    public $customerPaymentMethods;

    /**
     * All available shipping methods.
     *
     * @var Collection<ShippingMethod>
     */
    public $shippingMethods;

    /**
     * All available countries.
     *
     * @var array
     */
    public $countries;

    /**
     * Use state field.
     *
     * @var boolean
     */
    public $useState = true;

    /**
     * Name of the CMS page that hosts the signUp component.
     *
     * @var string
     */
    public $loginPage = 'login';

    /**
     * Current page URL.
     *
     * @var string
     */
    public $currentPage;

    /**
     * Account page.
     *
     * @var string
     */
    public $accountPage;

    /**
     * The product page.
     *
     * @var string
     */
    public $productPage;

    /**
     * The current user.
     *
     * @var User
     */
    public $user;

    /**
     * The currently active step.
     *
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
     *
     * @var Order
     */
    public $order;

    /**
     * The error message received from the PaymentProvider.
     *
     * @var string
     */
    public $paymentError;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'kodzero.posmall::lang.components.quickCheckout.details.name',
            'description' => 'kodzero.posmall::lang.components.quickCheckout.details.description',
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
            'loginPage' => [
                'title' => 'Name of the login page',
                'default' => 'login',
            ],
            'step' => [
                'type' => 'dropdown',
                'name' => 'kodzero.posmall::lang.components.checkout.properties.step.name',
                'default' => 'overview',
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
     *
     * @return array
     */
    public function getStepOptions()
    {
        return [
            'overview' => trans('kodzero.posmall::lang.components.checkout.steps.confirm'),
            'failed' => trans('kodzero.posmall::lang.components.checkout.steps.failed'),
            'cancelled' => trans('kodzero.posmall::lang.components.checkout.steps.cancelled'),
            'done' => trans('kodzero.posmall::lang.components.checkout.steps.done'),
            'payment' => trans('kodzero.posmall::lang.components.checkout.steps.payment'),
        ];
    }

    /**
     * The component is initialized.
     *
     * All child components get added.
     *
     * @return void
     */
    public function init()
    {
        $this->addJs('assets/address-zip-suggestions.js');
        $this->prepareAddressAutocompleteSettings();
        $this->step = $this->property('step', 'overview');
        $this->showNotesField = (bool)$this->property('showNotesField');

        // The default step is "overview". Since this component shows all steps on one screen,
        // the "payment" step can be redirected to the overview as well. The "payment" step is used
        // in case of subsequent payments for a previously failed order.
        if (! $this->step) {
            $this->step = 'overview';
        }

        if ($this->step === 'overview') {
            $this->addComponent(AddressSelector::class, 'billingAddressSelector', ['type' => 'billing', 'redirect' => 'quickCheckout']);
            $this->addComponent(AddressSelector::class, 'shippingAddressSelector', ['type' => 'shipping', 'redirect' => 'quickCheckout']);
        } elseif ($this->step === 'payment' || $this->step === 'cancelled') {
            $this->addComponent(PaymentMethodSelector::class, 'paymentMethodSelector', []);
            
            // Payment step guard
            // Redirect user to the login page when they request order details while not logged in
            $orderId = request()->get('order');

            if ($orderId && !FrontendAuth::check()) {
                throw new HttpResponseException(redirect($this->property('loginPage')));
            }
        }
        $this->setData();
    }

    /**
     * The component is run.
     *
     * @throws \Cms\Classes\CmsException
     * @return \Illuminate\Http\RedirectResponse
     */
    public function onRun()
    {
        // An off-site payment has been completed
        if ($type = request()->input('return')) {
            return $this->handleOffSiteReturn($type);
        }

        // If a invalid step is provided, show a 404 error page.
        if (! in_array($this->step, array_keys($this->getStepOptions()))) {
            return $this->controller->run('404');
        }

        // If an order has been created but something failed we can fetch the paymentError
        // from the order's payment logs.
        if ($this->step === 'failed' && $this->order) {
            $this->paymentError = optional($this->order->payment_logs->first())->message ?? 'Unknown error';
        }
    }

    /**
     * Where the magic happens.
     */
    public function onSubmit()
    {
        $data = post();

        // The user is not signed in. Let's create a new account.
        if (! $this->user) {
            $this->user = app(SignUpHandler::class)->handle($data, (bool)post('as_guest'));

            if (! $this->user) {
                throw new ValidationException(
                    [trans('kodzero.posmall::lang.components.quickCheckout.errors.signup_failed')]
                );
            }
            $this->cart = $this->cart->refresh();
        }

        if ($this->cart->payment_method_id === null && $this->order === null) {
            throw new ValidationException(
                [trans('kodzero.posmall::lang.components.checkout.errors.missing_settings')]
            );
        }

        $paymentData = post('payment_data', []);

        $model = $this->order ?? $this->cart;

        $paymentMethod = PaymentMethod::where('id', $model->payment_method_id)->firstOrFail();

        // Grab the PaymentGateway from the Service Container.
        $gateway = app(PaymentGateway::class);
        $gateway->init($paymentMethod, $paymentData);

        // If an order is already available, this is not the normal checkout flow but a
        // subsequent try to pay for an existing order for which the payment failed.
        $flow = $this->order ? 'payment' : 'checkout';

        if (! $this->order) {
            $attributes = [];

            if ($this->showNotesField) {
                $attributes['customer_notes'] = Str::limit(strip_tags((string)post('customer_notes', '')), 1000);
            }

            app(UsaTaxCheckoutGuard::class)->validate($this->cart);

            // Create the order first.
            try {
                $this->order = Order::fromCart($this->cart, $attributes);
            } catch (OutOfStockException $e) {
                throw new ValidationException(['cart' => $e->getMessage()]);
            }
        }

        // If the order was created successfully proceed with the payment.
        $paymentService = new PaymentService(
            $gateway,
            $this->order,
            $this->page->page->fileName
        );

        return $paymentService->process($flow);
    }

    /**
     * The shipping method has been changed.
     *
     * @throws ValidationException
     * @return array
     */
    public function onChangeShippingMethod()
    {
        $v = Validator::make(
            post() ?: [],
            [
                'id' => 'bail|required|integer|exists:kodzero_posmall_shipping_methods,id',
            ]
        );

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $id = post('id');

        if (! $this->shippingMethods || ! $this->shippingMethods->contains('id', (int)$id)) {
            throw new ValidationException(
                [
                    'id' => trans('kodzero.posmall::lang.components.shippingMethodSelector.errors.unavailable'),
                ]
            );
        }

        $method = ShippingMethod::where('id', $id)->first();
        $this->cart->setShippingMethod($method);
        $this->cart->validateShippingMethod();
        $this->setData();

        return $this->updateForm(
            [
                'method' => $method,
            ]
        );
    }

    /**
     * The payment method has been changed.
     *
     * @throws ValidationException
     * @return array
     */
    public function onChangePaymentMethod()
    {
        $rules = [
            'id' => 'bail|required|integer|exists:kodzero_posmall_payment_methods,id',
        ];

        $validation = Validator::make(post() ?: [], $rules);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $id = post('id');

        if (! $this->paymentMethods || ! $this->paymentMethods->contains('id', (int)$id)) {
            throw new ValidationException(
                [
                    'id' => trans('kodzero.posmall::lang.components.paymentMethodSelector.errors.unavailable'),
                ]
            );
        }

        $method = PaymentMethod::where('id', $id)->first();
        $this->cart->setPaymentMethod($method);
        $this->setData();

        return $this->updateForm(
            [
                'method' => $method,
            ]
        );
    }

    /**
     * The user removed an item from the cart.
     *
     * @return array
     */
    public function onRemoveProduct()
    {
        $id = $this->decode(input('id'));

        $cart = Cart::byUser(FrontendAuth::user());

        $product = $this->getProductFromCart($cart, $id);

        $cart->removeProduct($product);

        $this->setData();

        return $this->updateForm([
            'item' => $this->dataLayerArray($product->product, $product->variant),
            'quantity' => $product->quantity,
            'new_items_count' => optional($cart->products)->count() ?? 0,
            'new_items_quantity' => optional($cart->products)->sum('quantity') ?? 0,
        ]);
    }
    
    /**
     * The user removed a previously applied discount code from the cart.
     *
     * @throws ValidationException
     * @return array
     */
    public function onRemoveDiscountCode()
    {
        $id = $this->decode(input('id'));

        $cart = Cart::byUser(Auth::user());

        $cart->removeDiscountCodeById($id);

        $this->setData();

        return $this->updateForm([
            'new_items_count' => optional($cart->products)->count() ?? 0,
            'new_items_quantity' => optional($cart->products)->sum('quantity') ?? 0,
        ]);
    }

    /**
     * Re-renders all dynamic form components. Additional
     * data to be returned to the partial can be specified.
     *
     * @param array $withData
     *
     * @return array
     */
    public function updateForm(array $withData = [])
    {
        $withAlias = fn (string $partial) => $this->alias . '::' . $partial;

        return array_merge(
            [
                '.mall-quick-checkout__shipping-methods' => $this->renderPartial($withAlias('shippingmethod')),
                '.mall-quick-checkout__payment-methods' => $this->renderPartial($withAlias('paymentmethod')),
                '.mall-quick-checkout__cart' => $this->renderPartial($withAlias('cart')),
            ],
            $withData
        );
    }

    /**
     * Renders the payment form of the currently selected
     * payment method.
     *
     * @return string
     */
    public function renderPaymentForm()
    {
        if (! $this->cart->payment_method) {
            return '';
        }

        /** @var PaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);

        return $gateway
            ->getProviderById($this->cart->payment_method->payment_provider)
            ->renderPaymentForm($this->cart, [
                'addressZipHandler' => $this->alias . '::onSuggestAddressZip',
            ]);
    }

    /**
     * Fetch the item from the user's cart.
     *
     * This fails if an item is modified that is not in the
     * currently logged in user's cart.
     *
     * @param Cart $cart
     * @param mixed $id
     *
     * @throws ModelNotFoundException
     * @return mixed
     */
    protected function getProductFromCart(Cart $cart, $id)
    {
        return CartProduct::whereHas('cart', function ($query) use ($cart) {
            $query->where('id', $cart->id);
        })
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * This method sets all variables needed for this component to work.
     *
     * @return void
     */
    protected function setData()
    {
        $this->loginPage = $this->property('loginPage');
        $this->currentPage = $this->page->page->getBaseFileName();
        $this->setVar('accountPage', GeneralSettings::get('account_page'));

        $this->setVar('user', Auth::user());

        $cart = Cart::byUser($this->user);

        if (! $cart->payment_method_id) {
            $cart->setPaymentMethod(PaymentMethod::getDefault());
        }

        if ($this->user) {
            $cart->forgetFallbackShippingCountryId();
        } else {
            $shippingCountry = post('billing_country_id');
            $shippingState = post('billing_state_id');

            if (post('use_different_shipping')) {
                $shippingCountry = post('shipping_country_id');
                $shippingState = post('shipping_state_id');
            }
            $cart->setFallbackShippingCountryId($shippingCountry);
            $cart->setFallbackShippingStateId($shippingState);
        }

        $this->setVar('cart', $cart);

        $paymentMethod = PaymentMethod::where('id', $cart->payment_method_id)->first();

        if (! $paymentMethod) {
            $paymentMethod = PaymentMethod::getDefault();
            $cart->setPaymentMethod($paymentMethod);
        }

        $this->setVar('paymentMethods', PaymentMethod::getAvailableByCart($cart));

        $this->setVar('customerPaymentMethods', $this->getCustomerMethods());

        //        $this->setVar('dataLayer', $this->handleDataLayer());

        $this->countries = Country::getNameList();
        $this->useState = GeneralSettings::get('use_state', true);

        $this->setVar('shippingMethods', ShippingMethod::getAvailableByCart($cart));

        if ($this->user && $orderId = request()->get('order')) {
            $customer = $this->ensureCustomerForUser($this->user);
            $orderId = $this->decode($orderId);
            $this->setVar('order', $customer ? Order::byCustomer($customer)->where('id', $orderId)->first() : null);
        }
        
        $this->setVar('productPage', GeneralSettings::get('product_page'));
    }

    /**
     * Return all CustomerPaymentMethods grouped
     * by the payment method.
     *
     * @return Collection
     */
    protected function getCustomerMethods()
    {
        $customer = $this->ensureCustomerForUser(Auth::user());

        if (! $customer) {
            return collect([]);
        }

        return optional($customer->payment_methods)->groupBy('payment_method_id');
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

    /**
     * Return the dataLayer representation of an item.
     *
     * @param null $product
     * @param null $variant
     *
     * @return array
     */
    private function dataLayerArray($product = null, $variant = null)
    {
        $item = $variant ?? $product;

        if (!($item instanceof Product || $item instanceof Variant)) {
            return [];
        }

        return [
            'id' => $item->prefixedId,
            'name' => $product ? $product->name : $item->name,
            'price' => $item->price()->decimal,
            'brand' => optional($item->brand)->name,
            'category' => optional($item->categories->first())->name,
            'variant' => optional($variant)->name,
        ];
    }
}
