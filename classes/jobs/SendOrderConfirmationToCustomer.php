<?php

namespace KodZero\POSMall\Classes\Jobs;

use App;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use KodZero\POSMall\Models\Order;

/**
 * This Job sends the order confirmation to the customer. It also generates
 * a PDF invoice if the payment method requires one.
 */
class SendOrderConfirmationToCustomer
{
    public function fire(Job $job, $input)
    {
        if ($job->attempts() > 10) {
            logger()->critical(
                '[POSMall] Failed to send checkout confirmation mail after 10 attempts.',
                ['input' => $input]
            );
            $job->delete();
        }

        $order = Order::with(['customer', 'products'])->find($input['id']);
        $data  = [
            'order'       => $order,
            'account_url' => $input['account_url'],
            'order_url'   => $input['order_url'],
        ];

        App::setLocale($order->lang);
        Mail::send(
            $input['template'],
            $data,
            function (Message $message) use ($order) {
                $message->to($order->customer->user->email, $order->customer->name);

                if ($pdf = $order->getPDFInvoice()) {
                    $file_name = trans('kodzero.posmall::lang.order.order_file_name', ['order' => $order->id]);
                    $message->attachData($pdf->output(), sprintf('%s.pdf', $file_name));
                }
            }
        );

        $job->delete();
    }
}
