<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\Notification;

class NotificationTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if ($useDemo) {
            return;
        }
        
        Notification::firstOrCreate(['code' => 'kodzero.posmall::admin.checkout_succeeded'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::admin.checkout_succeeded',
            'name'        => trans('kodzero.posmall::demo.notifications.admin_checkout_succeeded.name'),
            'description' => trans('kodzero.posmall::demo.notifications.admin_checkout_succeeded.description'),
            'template'    => 'kodzero.posmall::mail.admin.checkout_succeeded',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::admin.checkout_failed'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::admin.checkout_failed',
            'name'        => trans('kodzero.posmall::demo.notifications.admin_checkout_failed.name'),
            'description' => trans('kodzero.posmall::demo.notifications.admin_checkout_failed.description'),
            'template'    => 'kodzero.posmall::mail.admin.checkout_failed',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::customer.created'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::customer.created',
            'name'        => trans('kodzero.posmall::demo.notifications.customer_created.name'),
            'description' => trans('kodzero.posmall::demo.notifications.customer_created.description'),
            'template'    => 'kodzero.posmall::mail.customer.created',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::checkout.succeeded'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::checkout.succeeded',
            'name'        => trans('kodzero.posmall::demo.notifications.checkout_succeeded.name'),
            'description' => trans('kodzero.posmall::demo.notifications.checkout_succeeded.description'),
            'template'    => 'kodzero.posmall::mail.checkout.succeeded',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::checkout.failed'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::checkout.failed',
            'name'        => trans('kodzero.posmall::demo.notifications.checkout_failed.name'),
            'description' => trans('kodzero.posmall::demo.notifications.checkout_failed.description'),
            'template'    => 'kodzero.posmall::mail.checkout.failed',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::order.shipped'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::order.shipped',
            'name'        => trans('kodzero.posmall::demo.notifications.order_shipped.name'),
            'description' => trans('kodzero.posmall::demo.notifications.order_shipped.description'),
            'template'    => 'kodzero.posmall::mail.order.shipped',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::order.state.changed'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::order.state.changed',
            'name'        => trans('kodzero.posmall::demo.notifications.order_state_changed.name'),
            'description' => trans('kodzero.posmall::demo.notifications.order_state_changed.description'),
            'template'    => 'kodzero.posmall::mail.order.state_changed',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::payment.paid'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::payment.paid',
            'name'        => trans('kodzero.posmall::demo.notifications.payment_paid.name'),
            'description' => trans('kodzero.posmall::demo.notifications.payment_paid.description'),
            'template'    => 'kodzero.posmall::mail.payment.paid',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::payment.failed'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::payment.failed',
            'name'        => trans('kodzero.posmall::demo.notifications.payment_failed.name'),
            'description' => trans('kodzero.posmall::demo.notifications.payment_failed.description'),
            'template'    => 'kodzero.posmall::mail.payment.failed',
        ]);

        Notification::firstOrCreate(['code' => 'kodzero.posmall::payment.refunded'], [
            'enabled'     => true,
            'code'        => 'kodzero.posmall::payment.refunded',
            'name'        => trans('kodzero.posmall::demo.notifications.payment_refunded.name'),
            'description' => trans('kodzero.posmall::demo.notifications.payment_refunded.description'),
            'template'    => 'kodzero.posmall::mail.payment.refunded',
        ]);
    }
}
