<?php

namespace KodZero\POSMall\Classes\Registration;

trait BootMails
{
    public function registerMailTemplates()
    {
        return [
            'kodzero.posmall::mail.customer.created',
            'kodzero.posmall::mail.order.state_changed',
            'kodzero.posmall::mail.order.shipped',
            'kodzero.posmall::mail.checkout.succeeded',
            'kodzero.posmall::mail.checkout.failed',
            'kodzero.posmall::mail.payment.failed',
            'kodzero.posmall::mail.payment.paid',
            'kodzero.posmall::mail.payment.refunded',
            'kodzero.posmall::mail.admin.checkout_succeeded',
            'kodzero.posmall::mail.admin.checkout_failed',
            'kodzero.posmall::mail.admin.payment_paid',
            'kodzero.posmall::mail.tests.failed',
        ];
    }

    public function registerMailPartials()
    {
        return [
            'posmall.order.table'         => 'kodzero.posmall::mail._partials.order.table',
            'posmall.order.tracking'      => 'kodzero.posmall::mail._partials.order.tracking',
            'posmall.order.addresses'     => 'kodzero.posmall::mail._partials.order.addresses',
            'posmall.order.payment_state' => 'kodzero.posmall::mail._partials.order.payment_state',
            'posmall.customer.address'    => 'kodzero.posmall::mail._partials.customer.address',
        ];
    }
}
