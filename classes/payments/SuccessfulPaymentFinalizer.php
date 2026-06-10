<?php

namespace KodZero\POSMall\Classes\Payments;

use DB;
use Event;
use KodZero\POSMall\Models\Order;
use Throwable;

class SuccessfulPaymentFinalizer
{
    public function finalize(PaymentResult $result): bool
    {
        if (! $result->order || ! $result->order->id) {
            return false;
        }

        $shouldFire = false;

        DB::transaction(function () use ($result, &$shouldFire) {
            $order = Order::where('id', $result->order->id)->lockForUpdate()->first();

            if (! $order) {
                return;
            }

            if ($order->succeeded_at) {
                $result->order = $order;

                return;
            }

            $order->succeeded_at = now();
            Order::where('id', $order->id)->update(['succeeded_at' => $order->succeeded_at]);

            $result->order = $order;
            $shouldFire = true;
        });

        if ($shouldFire) {
            try {
                Event::fire('posmall.checkout.succeeded', [$result]);
            } catch (Throwable $e) {
                logger()->error('POSMall: checkout succeeded listener failed.', [
                    'order_id' => optional($result->order)->id,
                    'payment_method_id' => optional($result->order)->payment_method_id,
                    'exception_message' => $e->getMessage(),
                    'exception_trace' => $e->getTraceAsString(),
                    'exception' => $e,
                ]);
            }
        }

        return $shouldFire;
    }
}
