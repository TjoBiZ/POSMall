<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Auth;
use Illuminate\Support\Collection;
use KodZero\POSMall\Classes\PaymentState\FailedState;
use KodZero\POSMall\Classes\PaymentState\PendingState;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\OrderState;

/**
 * The OrdersList component displays a list of all the user's orders.
 */
class OrdersList extends POSMallComponent
{
    /**
     * Array of all orders.
     *
     * @var Collection
     */
    public $orders;

    /**
     * All available countries.
     *
     * @var Collection
     */
    public $countries;

    /**
     * Link to pay a pending order.
     *
     * @var string
     */
    public $paymentLink;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.ordersList.details.name',
            'description' => 'kodzero.posmall::lang.components.ordersList.details.description',
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

    /**
     * The component is initialized.
     *
     * @return void
     */
    public function init()
    {
        $user = Auth::user();
        $customer = $this->ensureCustomerForUser($user);

        if (!$user || !$customer) {
            return;
        }
        
        $this->paymentLink = $this->getPaymentLink();
        $this->orders = Order::byCustomer($customer)
            ->with([
                'products',
                'products.product',
                'products.product.image_sets',
                'products.variant',
                'products.variant.image_sets',
                'order_state',
                'payment_method',
            ])
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
    }

    public function onCancelOrder()
    {
        $user = Auth::user();
        $customer = $this->ensureCustomerForUser($user);

        if (! $user || ! $customer) {
            return;
        }

        $state = OrderState::where('flag', OrderState::FLAG_CANCELLED)->first();

        $order = Order::byCustomer($customer)->where('id', post('id'))->firstOrFail();
        $order->order_state = $state;
        $order->save();
    }

    /**
     * Get the URL of the payment page.
     *
     * @return string
     */
    protected function getPaymentLink()
    {
        $page = GeneralSettings::get('checkout_page');

        return $this->controller->pageUrl($page, ['step' => 'payment']);
    }

    public function isPayable(Order $order): bool
    {
        if ($order->is_paid || ($order->order_state && $order->is_cancelled)) {
            return false;
        }

        return in_array($order->payment_state, [
            PendingState::class,
            FailedState::class,
        ], true);
    }

    public function paymentUrl(Order $order): string
    {
        return $this->controller->pageUrl('posmall-order-pay', ['hash' => $order->hashId]);
    }
}
