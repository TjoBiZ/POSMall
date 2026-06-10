<?php

namespace KodZero\POSMall\Classes\Events;

use Backend\Facades\Backend;
use Cms\Classes\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use October\Rain\Database\Collection;
use October\Rain\Mail\Mailable;
use KodZero\POSMall\Classes\Jobs\SendOrderConfirmationToCustomer;
use KodZero\POSMall\Classes\PaymentState\FailedState;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Classes\PaymentState\RefundedState;
use KodZero\POSMall\Classes\User\Settings as UserSettings;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Notification;
use KodZero\POSMall\Models\Order;
use PDOException;
use RainLab\Translate\Classes\Translator;

class MailingEventHandler
{
    public $enabledNotifications = [];

    public function __construct()
    {
        try {
            $this->enabledNotifications = Notification::getEnabled();
        } catch (PDOException $e) {
            // The database connection might not be available depending on the
            // current application state.
            $this->enabledNotifications = new Collection();
        }
    }

    /**
     * Subscribe conditionally to all relevant mall events.
     *
     * @param $events
     */
    public function subscribe($events)
    {
        $eventMap = [
            'kodzero.posmall::customer.created'    => [
                'event'   => 'posmall.customer.afterSignup',
                'handler' => self::class . '@customerCreated',
            ],
            'kodzero.posmall::order.state.changed' => [
                'event'   => 'posmall.order.state.changed',
                'handler' => self::class . '@orderStateChanged',
            ],
            'kodzero.posmall::order.shipped'       => [
                'event'   => 'posmall.order.shipped',
                'handler' => self::class . '@orderShipped',
            ],
        ];

        foreach ($eventMap as $notification => $data) {
            if ($this->enabledNotifications->has($notification)) {
                $events->listen($data['event'], $data['handler']);
            }
        }

        $events->listen('posmall.order.payment_state.changed', self::class . '@orderPaymentStateChanged');
        $events->listen('posmall.checkout.succeeded', self::class . '@checkoutSucceeded');
        $events->listen('posmall.checkout.failed', self::class . '@checkoutFailed');
    }

    /**
     * A customer has signed up.
     *
     * @param $user
     * @param mixed $handler
     *
     * @throws \Cms\Classes\CmsException
     */
    public function customerCreated($handler, $user)
    {
        $customer = Customer::forUser($user);

        // Don't send the mail when customer signs up as a guest or when this notification is disabled.
        if (! $customer || $customer->is_guest || ! $this->enabledNotifications->has('kodzero.posmall::customer.created')) {
            return;
        }

        // RainLab.User 3.0
        if (class_exists(\RainLab\User\Models\Setting::class)) {
            $needsConfirmation = false;
            $confirmCode       = null;
            $confirmUrl        = null;
        } else {
            $needsConfirmation = UserSettings::get('activate_mode') === UserSettings::ACTIVATE_USER;
            $confirmCode       = implode('!', [$user->id, $user->getActivationCode()]);
            $confirmUrl        = $this->getAccountUrl('confirmation') . '?code=' . $confirmCode;
        }

        $data = [
            'user'         => $user,
            'customer'     => $customer,
            'confirm'      => $needsConfirmation,
            'confirm_url'  => $confirmUrl,
            'confirm_code' => $confirmCode,
        ];

        Mail::queue($this->template('kodzero.posmall::customer.created'), $data, function ($message) use ($user, $customer) {
            if (class_exists(Translator::class) && method_exists($message, 'locale')) {
                $message->locale(Translator::instance()->getLocale());
            }
            $message->to($user->email, $customer->name);
        });
    }

    /**
     * A checkout was successful.
     *
     * @param $result
     *
     * @throws \Cms\Classes\CmsException
     */
    public function checkoutSucceeded($result)
    {
        // Notify the customer
        if (
            $this->enabledNotifications->has('kodzero.posmall::checkout.succeeded')
            && $this->shouldSendOrderNotificationToCustomer()
            && $this->customerEmailForOrder($result->order)
        ) {
            $input = [
                'id'          => $result->order->id,
                'template'    => $this->template('kodzero.posmall::checkout.succeeded'),
                'account_url' => $this->getAccountUrl(),
                'order_url'   => $this->getBackendOrderUrl($result->order),
            ];
            // Push the PDF generation and mail send call to the queue.
            Queue::push(SendOrderConfirmationToCustomer::class, $input);
        }

        // Notify the admin
        if (
            $this->enabledNotifications->has('kodzero.posmall::admin.checkout_succeeded')
            && $adminMail = $this->orderNotificationEmail()
        ) {
            $data = [
                'order'       => $result->order->fresh(['products', 'customer']),
                'account_url' => $this->getAccountUrl(),
                'order_url'   => $this->getBackendOrderUrl($result->order),
            ];
            Mail::queue(
                $this->template('kodzero.posmall::admin.checkout_succeeded'),
                $data,
                function (Mailable $message) use ($adminMail) {
                    $message->to($adminMail);
                }
            );
        }
    }

    /**
     * A checkout has failed.
     *
     * @param $result
     *
     * @throws \Cms\Classes\CmsException
     */
    public function checkoutFailed($result)
    {
        $data = [
            'order'       => $result->order->fresh(['products', 'customer']),
            'account_url' => $this->getAccountUrl(),
            'order_url'   => $this->getBackendOrderUrl($result->order),
        ];

        // Notify the customer
        if (
            $this->enabledNotifications->has('kodzero.posmall::checkout.failed')
            && $this->shouldSendOrderNotificationToCustomer()
            && $customerEmail = $this->customerEmailForOrder($result->order)
        ) {
            Mail::queue($this->template('kodzero.posmall::checkout.failed'), $data, function ($message) use ($result, $data) {
                $this->handleLocale($message, $data['order']);
                $message->to($result->order->customer->user->email, $result->order->customer->name);
            });
        }

        // Notify the admin
        if (
            $this->enabledNotifications->has('kodzero.posmall::admin.checkout_failed')
            && $adminMail = $this->orderNotificationEmail()
        ) {
            Mail::queue(
                $this->template('kodzero.posmall::admin.checkout_failed'),
                $data,
                function ($message) use ($adminMail) {
                    $message->to($adminMail);
                }
            );
        }
    }

    /**
     * The state of an order has changed.
     *
     * @param $order
     */
    public function orderStateChanged($order)
    {
        if (! $order->stateNotification) {
            return;
        }

        $data = [
            'order' => $order->load(['order_state']),
            'account_url' => $this->getAccountUrl(),
        ];

        Mail::queue($this->template('kodzero.posmall::order.state.changed'), $data, function ($message) use ($order) {
            $this->handleLocale($message, $order);
            $message->to($order->customer->user->email, $order->customer->name);
        });
    }

    /**
     * The order has been shipped.
     *
     * @param $order
     *
     * @throws \Cms\Classes\CmsException
     */
    public function orderShipped($order)
    {
        if (! $order->shippingNotification) {
            return;
        }

        $data = [
            'order'       => $order->load(['order_state']),
            'account_url' => $this->getAccountUrl(),
        ];

        Mail::queue($this->template('kodzero.posmall::order.shipped'), $data, function ($message) use ($order) {
            $this->handleLocale($message, $order);
            $message->to($order->customer->user->email, $order->customer->name);
        });
    }

    /**
     * The payment state of an order has changed.
     * Depending on the new order state a different mail template will be used.
     *
     * @param $order
     *
     * @throws \Cms\Classes\CmsException
     */
    public function orderPaymentStateChanged($order)
    {
        $attr = 'payment_state';

        if (! $order->isDirty($attr) || $order->getOriginal($attr) === $order->getAttribute($attr)) {
            return;
        }

        switch ($order->getAttribute($attr)) {
            case FailedState::class:
                $view = 'failed';
                break;
            case PaidState::class:
                $view = 'paid';
                break;
            case RefundedState::class:
                $view = 'refunded';
                break;
            default:
                // The customer is not informed about any other state.
                return;
        }

        // This notification is disabled.
        if (! $this->enabledNotifications->has('kodzero.posmall::payment.' . $view)) {
            return;
        }

        $data = [
            'order'       => $order->load(['order_state', 'payment_logs']),
            'account_url' => $this->getAccountUrl(),
        ];

        Mail::queue(
            $this->template('kodzero.posmall::payment.' . $view),
            $data,
            function ($message) use ($order) {
                $this->handleLocale($message, $order);
                $message->to($order->customer->user->email, $order->customer->name);
            }
        );

        // Notify the admin about succeeded payments.
        $failedBecamePaid = $order->getOriginal($attr) === FailedState::class && $order->getAttribute($attr) === PaidState::class;

        if ($failedBecamePaid && $adminMail = GeneralSettings::get('admin_email')) {
            Mail::queue(
                'kodzero.posmall::mail.admin.payment_paid',
                $data,
                function ($message) use ($adminMail) {
                    $message->to($adminMail);
                }
            );
        }
    }

    /**
     * Return the user defined mail template for a given event code.
     *
     * @param mixed $code
     * @return string
     */
    protected function template($code)
    {
        return $this->enabledNotifications->get($code);
    }

    protected function orderNotificationEmail(): ?string
    {
        $email = trim((string)GeneralSettings::get('order_notification_email'));

        if ($email === '') {
            $email = trim((string)GeneralSettings::get('admin_email'));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    protected function shouldSendOrderNotificationToCustomer(): bool
    {
        return (bool)GeneralSettings::get('send_order_notification_to_customer', true);
    }

    protected function customerEmailForOrder(Order $order): ?string
    {
        $email = trim((string)optional(optional($order->customer)->user)->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Return the direct URL to a customer's account page.
     *
     * @param string $page
     *
     * @throws \Cms\Classes\CmsException
     * @return string
     */
    protected function getAccountUrl($page = 'orders'): string
    {
        $controller = Controller::getController() ?: new Controller();

        return $controller->pageUrl(
            GeneralSettings::get('account_page'),
            ['page' => $page]
        );
    }

    /**
     * Returns the direct URL to the order details.
     *
     * @param $order
     *
     * @return string
     */
    protected function getBackendOrderUrl($order): string
    {
        return Backend::url('kodzero/posmall/orders/show/' . $order->id);
    }

    /**
     * Set the locale on the message, based on the order.
     *
     * @param Mailable $message
     * @param Order $order
     */
    protected function handleLocale(Mailable $message, Order $order)
    {
        if ($order->lang && method_exists($message, 'locale')) {
            $message->locale($order->lang);
        }
    }
}
