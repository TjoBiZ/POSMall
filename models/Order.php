<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use DB;
use Event;
use Illuminate\Support\Facades\Queue;
use Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\Exceptions\InvalidDiscountException;
use KodZero\POSMall\Classes\Jobs\SendVirtualProductFiles;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Classes\PaymentState\PendingState;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\Traits\JsonPrice;
use KodZero\POSMall\Classes\Traits\PDFMaker;
use KodZero\POSMall\Classes\Utils\Money;
use RuntimeException;
use Session;
use System\Classes\PluginManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Order extends Model
{
    use Validation {
        forceSave as protected forceSaveWithoutOrderNumberTransaction;
    }
    use SoftDelete;
    use JsonPrice {
        useCurrency as fallbackCurrency;
    }
    use HashIds;
    use PDFMaker;

    public $rules = [
        'currency'                         => 'required',
        'shipping_address_same_as_billing' => 'required|boolean',
        'billing_address'                  => 'required',
        'lang'                             => 'required',
        'ip_address'                       => 'required',
        'customer_id'                      => 'required|exists:kodzero_posmall_customers,id',
    ];

    public $jsonable = [
        'billing_address',
        'shipping_address',
        'custom_fields',
        'taxes',
        'payment',
        'currency',
        'discounts',
        'shipping',
    ];

    public $table = 'kodzero_posmall_orders';

    public $hasOne = ['payment_log' => PaymentLog::class];

    public $hasMany = [
        'products'         => OrderProduct::class,
        'virtual_products' => [OrderProduct::class, 'scope' => 'virtual'],
        'payment_logs'     => [PaymentLog::class, 'order' => 'created_at DESC'],
    ];

    public $belongsTo = [
        'payment_method'          => [
            PaymentMethod::class,
            'deleted'   => true,
            'scope'     => 'all',
        ],
        'customer_payment_method' => [
            CustomerPaymentMethod::class,
            'deleted'   => true,
        ],
        'order_state'             => [
            OrderState::class,
            'deleted'   => true,
            'scope'     => 'all',
        ],
        'customer'                => [Customer::class, 'deleted' => true],
        'cart'                    => [Cart::class, 'deleted' => true],
        'api_token'               => [ApiToken::class],
        'vendor'                  => [Vendor::class],
        'channel'                 => [Channel::class],
        'warehouse'               => [Warehouse::class],
    ];

    public $casts = [
        'shipping_address_same_as_billing' => 'boolean',
    ];

    /**
     * Use to define if the shipping notification should be sent.
     * @var bool
     */
    public $shippingNotification = false;

    /**
     * Use to define if the state change notification should be sent.
     * @var bool
     */
    public $stateNotification = true;

    protected $dates = [
        'deleted_at',
        'shipped_at',
        'paid_at',
        'payment_link_expires_at',
        'payment_link_used_at',
    ];

    public function beforeCreate()
    {
        if (! $this->order_number) {
            $this->setOrderNumber();
        }
        $this->payment_hash = str_random(10);
    }

    public function save(?array $options = [], $sessionKey = null)
    {
        if ($this->shouldWrapOrderNumberCreationInTransaction()) {
            return DB::transaction(function () use ($options, $sessionKey) {
                return parent::save($options, $sessionKey);
            });
        }

        return parent::save($options, $sessionKey);
    }

    public function forceSave($options = null, $sessionKey = null)
    {
        if ($this->shouldWrapOrderNumberCreationInTransaction()) {
            return DB::transaction(function () use ($options, $sessionKey) {
                return $this->forceSaveWithoutOrderNumberTransaction($options, $sessionKey);
            });
        }

        return $this->forceSaveWithoutOrderNumberTransaction($options, $sessionKey);
    }

    public function afterUpdate()
    {
        if ($this->isDirty('payment_state')) {
            // Don't trigger payment changes during the checkout flow. A posmall.checkout.succeeded
            // Event will already be triggered in the PaymentRedirector.
            $flow = session()->get('posmall.checkout.flow');

            if ($flow !== 'checkout') {
                Event::fire('posmall.order.payment_state.changed', [$this]);
            }

            // If the order became paid, distribute all virtual products.
            if ($this->payment_state === PaidState::class && $this->paid_at === null) {
                if ($this->virtual_products->count() > 0) {
                    Queue::push(SendVirtualProductFiles::class, ['order' => $this->id]);
                }
                $this->paid_at = Carbon::today();
                $this->saveQuietly();
            }
        }

        if ($this->isDirty('order_state_id')) {
            Event::fire('posmall.order.state.changed', [$this]);
        }

        if ($this->isDirty('tracking_url') || $this->isDirty('tracking_number')) {
            Event::fire('posmall.order.tracking.changed', [$this]);
        }

        if ($this->getOriginal('shipped_at') === null && $this->isDirty('shipped_at')) {
            Event::fire('posmall.order.shipped', [$this]);
        }
    }

    public function afterDelete()
    {
        $this->products->each->delete();
        $this->payment_logs->each->delete();

        if ($this->cart) {
            $this->cart->delete();
        }
    }

    public function getIsShippedAttribute()
    {
        return $this->shipped_at !== null;
    }

    public function getSafeTrackingUrlAttribute(): ?string
    {
        $url = trim((string)$this->tracking_url);

        if ($url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array(strtolower((string)$scheme), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    public function getIsCancelledAttribute()
    {
        return $this->order_state->flag === OrderState::FLAG_CANCELLED;
    }

    public static function byCustomer(Customer $customer)
    {
        return static::where('customer_id', $customer->id);
    }

    public static function fromCart(Cart $cart, array $additionalAttributes = []): self
    {
        $cart->loadMissing(['products.product.brand']);

        static::validateCartForOrderCreation($cart);

        try {
            $order = DB::transaction(function () use ($additionalAttributes, $cart) {
                $cart = static::lockCartForOrderCreation($cart);
                static::validateCartForOrderCreation($cart);

                Event::fire('posmall.order.beforeCreate', [$cart]);

                $initialOrderStatus = OrderState::where('flag', OrderState::FLAG_NEW)->first();

                if (! $initialOrderStatus) {
                    throw new RuntimeException('You have to create an order state with the "new" flag before accepting orders!');
                }

                if ($cart->products->count() < 1) {
                    throw new ValidationException(['Your order is empty. Please add a product to the cart.']);
                }

                $cart->validateShippingMethod();

                if ($cart->shipping_method_id === null && ! $cart->is_virtual) {
                    throw new ValidationException(['Your order has no shipping method set. Please select a shipping method.']);
                }

                $totals = $cart->totals;

                $order                                          = new static();
                $order->session_id                              = session()->getId();
                $order->currency                                = Currency::activeCurrency();
                $order->lang                                    = $order->getLocale();
                $order->shipping_address_same_as_billing        = $cart->shipping_address_same_as_billing;
                $order->billing_address                         = $cart->billing_address;
                $order->shipping_address                        = $cart->shipping_address;
                $order->shipping                                = $totals->shippingTotal();
                $order->payment                                 = $totals->paymentTotal();
                $order->taxes                                   = $totals->taxes();
                $order->discounts                               = $totals->appliedDiscounts();
                $order->ip_address                              = request()->ip();
                $order->customer_id                             = optional($cart->customer)->id
                    ?? $cart->customer_id;
                $order->payment_method_id                       = $cart->payment_method_id;
                $order->customer_payment_method_id              = $cart->customer_payment_method_id;
                $order->payment_state                           = PendingState::class;
                $order->order_state_id                          = $initialOrderStatus->id;
                $order->is_virtual                              = $cart->is_virtual;

                $order->attributes['total_shipping_pre_taxes']  = $order->round($totals->shippingTotal()->totalPreTaxes());
                $order->attributes['total_shipping_taxes']      = $order->round($totals->shippingTotal()->totalTaxes());
                $order->attributes['total_shipping_post_taxes'] = $order->round($totals->shippingTotal()->totalPostTaxes());
                $order->attributes['total_payment_pre_taxes']   = $order->round($totals->paymentTotal()->totalPreTaxes());
                $order->attributes['total_payment_taxes']       = $order->round($totals->paymentTotal()->totalTaxes());
                $order->attributes['total_payment_post_taxes']  = $order->round($totals->paymentTotal()->totalPostTaxes());
                $order->attributes['total_product_pre_taxes']   = $order->round($totals->productPreTaxes());
                $order->attributes['total_product_taxes']       = $order->round($totals->productTaxes());
                $order->attributes['total_product_post_taxes']  = $order->round($totals->productPostTaxes());
                $order->attributes['total_pre_payment']         = $order->round($totals->totalPrePayment());
                $order->attributes['total_pre_taxes']           = $order->round($totals->totalPreTaxes());
                $order->attributes['total_taxes']               = $order->round($totals->totalTaxes());
                $order->attributes['total_post_taxes']          = $order->round($totals->totalPostTaxes());
                $order->total_weight                            = $order->round($totals->weightTotal());

                $order->forceFill($additionalAttributes);

                $order->save();

                $cart
                    ->products
                    ->each(function (CartProduct $entry) use ($order) {
                        $entry->moveToOrder($order);
                    });

                Event::fire('posmall.order.afterCreate', [$order, $cart]);

                $cart->updateDiscountUsageCount();

                $cart->delete(); // We can empty the cart once the order is created.

                return $order;
            });
        } catch (ValidationException $e) {
            static::persistCartValidationCleanup($cart);

            throw $e;
        }

        // Drop any saved payment information since the order has been
        // created successfully.
        Session::forget('posmall.payment_method.data');

        // Remove any enforced shipping state.
        Session::forget('posmall.shipping.enforced.price');
        Session::forget('posmall.shipping.enforced.name');

        Event::fire('posmall.order.created', [$order]);

        return $order;
    }

    /**
     * Returns the pdf invoice for this order.
     * If no invoice is available false is returned.
     *
     * @throws \Cms\Classes\CmsException
     * @return PDF|bool
     */
    public function getPDFInvoice()
    {
        if ($this->payment_method->pdf_partial) {
            return $this->makePDFFromDir($this->payment_method->pdf_partial, ['order' => $this]);
        }

        return false;
    }

    public function getPriceColumns(): array
    {
        return [
            'total_shipping_pre_taxes',
            'total_shipping_taxes',
            'total_shipping_post_taxes',
            'total_payment_pre_taxes',
            'total_payment_taxes',
            'total_payment_post_taxes',
            'total_product_pre_taxes',
            'total_product_taxes',
            'total_product_post_taxes',
            'total_taxes',
            'total_post_taxes',
            'total_pre_taxes',
        ];
    }

    /**
     * Returns the amount of the order in the selected currency.
     * This is used in the PaymentProvider classes.
     *
     * @return float
     */
    public function getTotalInCurrencyAttribute()
    {
        $total = (int)$this->getOriginal('total_post_taxes');

        return app(Money::class)->round($total, $this->currency['decimals']);
    }

    public function getPaymentStateLabelAttribute()
    {
        return $this->payment_state::label();
    }

    public function getOrderStateLabelAttribute()
    {
        return $this->order_state->name;
    }

    public function getShippingAddressStringAttribute()
    {
        return implode("\n", $this->shipping_address);
    }

    public function getIsPaidAttribute()
    {
        return $this->payment_state === PaidState::class;
    }

    public function totalPreTaxes()
    {
        return $this->toPriceModel('total_pre_taxes');
    }

    public function totalTaxes()
    {
        return $this->toPriceModel('total_taxes');
    }

    public function totalPostTaxes()
    {
        return $this->toPriceModel('total_post_taxes');
    }

    public function totalProductPreTaxes()
    {
        return $this->toPriceModel('total_product_pre_taxes');
    }

    public function totalProductTaxes()
    {
        return $this->toPriceModel('total_product_taxes');
    }

    public function totalProductPostTaxes()
    {
        return $this->toPriceModel('total_product_post_taxes');
    }

    public function totalShippingPreTaxes()
    {
        return $this->toPriceModel('total_shipping_pre_taxes');
    }

    public function totalShippingTaxes()
    {
        return $this->toPriceModel('total_shipping_taxes');
    }

    public function totalShippingPostTaxes()
    {
        return $this->toPriceModel('total_shipping_post_taxes');
    }

    public function totalPaymentPreTaxes()
    {
        return $this->toPriceModel('total_payment_pre_taxes');
    }

    public function totalPaymentTaxes()
    {
        return $this->toPriceModel('total_payment_taxes');
    }

    public function totalPaymentPostTaxes()
    {
        return $this->toPriceModel('total_payment_post_taxes');
    }

    /**
     * POSMall privacy cleanup callback used by the external cleanup integration.
     *
     * @param Carbon $deadline
     * @param int $keepDays
     */
    public function gdprCleanup(Carbon $deadline, int $keepDays)
    {
        self::where('created_at', '<', $deadline)
            ->withTrashed()
            ->whereHas('order_state', function ($q) {
                $q->where('flag', OrderState::FLAG_COMPLETE);
            })
            ->get()
            ->each(function (Order $order) {
                DB::transaction(function () use ($order) {
                    $order->forceDelete();
                });
            });
    }

    /**
     * This is here to provide custom rounding options for the
     * end-user in future versions (like round to .05)
     * @param mixed $amount
     */
    protected function round($amount)
    {
        return round($amount);
    }

    /**
     * Sets the order number to the next higher value.
     */
    protected function setOrderNumber()
    {
        $lockKey = 'kodzero_posmall_orders_order_number';

        // PostgreSQL transaction-scoped lock: released automatically with the surrounding order creation transaction.
        DB::select('select pg_advisory_xact_lock(hashtext(?)::bigint)', [$lockKey]);

        $numbers = DB::table($this->getTable())
            ->selectRaw('max(order_number) as max')
            ->first();

        $start = $numbers->max;

        if (is_null($start) || $start === 0) {
            $start = (int)GeneralSettings::get('order_start');
        }

        $this->order_number = $start + 1;
    }

    protected function shouldWrapOrderNumberCreationInTransaction(): bool
    {
        return ! $this->exists
            && ! $this->order_number
            && DB::connection()->transactionLevel() === 0;
    }

    protected static function lockCartForOrderCreation(Cart $cart): Cart
    {
        $lockedCart = Cart::whereKey($cart->getKey())->lockForUpdate()->first();

        if (! $lockedCart) {
            throw new ValidationException(['cart' => trans('kodzero.posmall::frontend.cart.products_unavailable')]);
        }

        $products = $lockedCart
            ->products()
            ->with(['product.brand'])
            ->orderBy('id')
            ->get();

        $lockedCart->setRelation('products', $products);

        return $lockedCart;
    }

    protected static function validateCartForOrderCreation(Cart $cart): void
    {
        // Make sure all products in the cart are still published.
        $removed = $cart->removeUnpublishedProducts();

        if ($removed->count() > 0) {
            throw new ValidationException(['cart' => trans('kodzero.posmall::frontend.cart.products_unavailable')]);
        }

        // Ensure all applied discounts are still valid.
        try {
            $cart->validateDiscounts();
        } catch (InvalidDiscountException $e) {
            throw new ValidationException(['cart' => trans('kodzero.posmall::frontend.cart.discounts_no_longer_valid')]);
        }
    }

    protected static function persistCartValidationCleanup(Cart $cart): void
    {
        $cart = Cart::whereKey($cart->getKey())->first();

        if (! $cart) {
            return;
        }

        $cart->loadMissing(['products.product.brand']);

        try {
            static::validateCartForOrderCreation($cart);
        } catch (ValidationException $e) {
            // The original checkout validation error is rethrown by the caller.
        }
    }

    protected function useCurrency()
    {
        if ($this->currency) {
            return new Currency($this->currency);
        }

        return $this->fallbackCurrency();
    }

    protected function toPriceModel(string $key): Price
    {
        $currency = $this->useCurrency();
        $priceModel = new Price([
            'currency_id' => $currency->id,
            'price'       => $this->getOriginal($key) / 100,
        ]);
        $priceModel->setRelation('currency', $currency);

        return $priceModel;
    }

    protected function getLocale()
    {
        if (PluginManager::instance()->exists('RainLab.Translate')) {
            $translator = \RainLab\Translate\Classes\Translator::instance();

            return $translator->getLocale() ?: $translator->getDefaultLocale() ?: app()->getLocale() ?: 'default';
        }

        return 'default';
    }
}
