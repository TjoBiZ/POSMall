<?php

namespace KodZero\POSMall\Classes\Stats;

use DB;
use Illuminate\Support\Carbon;
use KodZero\POSMall\Classes\PaymentState\PaidState;
use KodZero\POSMall\Classes\PaymentState\PaymentState;
use KodZero\POSMall\Models\Order;
use KodZero\POSMall\Models\OrderState;

class OrdersStats
{
    protected $ordersTable;

    protected $statesTable;

    protected $cancelledStateId;

    public function __construct()
    {
        $this->ordersTable      = (new Order())->table;
        $this->statesTable      = (new OrderState())->table;
        $this->cancelledStateId = optional(OrderState::where('flag', OrderState::FLAG_CANCELLED)->first())->id;
    }

    public function count(): int
    {
        return DB::table($this->ordersTable)
            ->whereNull('deleted_at')
            ->count();
    }

    public function perWeekCount(): float
    {
        $firstOrder = DB::table($this->ordersTable)
            ->where('order_state_id', '<>', $this->cancelledStateId)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'ASC')
            ->first(['created_at']);

        if (! $firstOrder) {
            return 0;
        }

        $weeks = Carbon::createFromFormat('Y-m-d H:i:s', $firstOrder->created_at)->diffInWeeks(today());

        if ($weeks < 1) {
            return $this->count();
        }

        return round($this->count() / $weeks, 2);
    }

    public function grandTotal(): int
    {
        return DB::table($this->ordersTable)
            ->where('payment_state', PaidState::class)
            ->where('order_state_id', '<>', $this->cancelledStateId)
            ->whereNull('deleted_at')
            ->sum('total_pre_payment');
    }

    public function byState(): array
    {
        return DB::table($this->ordersTable)
            ->whereNull($this->ordersTable . '.deleted_at')
            ->leftJoin($this->statesTable, "{$this->ordersTable}.order_state_id", '=', "{$this->statesTable}.id")
            ->select(
                "{$this->statesTable}.name as label",
                "{$this->statesTable}.color",
                DB::raw('count(order_state_id) as value')
            )
            ->groupBy("{$this->ordersTable}.order_state_id", "{$this->statesTable}.name", "{$this->statesTable}.color")
            ->get()
            ->toArray();
    }

    public function byPaymentState(): array
    {
        return DB::table($this->ordersTable)
            ->whereNull($this->ordersTable . '.deleted_at')
            ->select('payment_state', DB::raw('count(payment_state) as value'))
            ->groupBy('payment_state')
            ->get()
            ->map(function ($row) {
                /** @var PaymentState $inst */
                $inst = $row->payment_state;

                return (object)[
                    'color' => $inst::color(),
                    'value' => $row->value,
                    'label' => $inst::label(),
                ];
            })
            ->toArray();
    }

    public function byVendor(): array
    {
        return $this->byContext('kodzero_posmall_vendors', 'vendor_id', 'No vendor', '#6c757d');
    }

    public function byChannel(): array
    {
        return $this->byContext('kodzero_posmall_channels', 'channel_id', 'No channel', '#0d6efd');
    }

    public function byWarehouse(): array
    {
        return $this->byContext('kodzero_posmall_warehouses', 'warehouse_id', 'No warehouse', '#198754');
    }

    protected function byContext(string $contextTable, string $foreignKey, string $emptyLabel, string $color): array
    {
        return DB::table($this->ordersTable)
            ->whereNull($this->ordersTable . '.deleted_at')
            ->leftJoin($contextTable, "{$this->ordersTable}.{$foreignKey}", '=', "{$contextTable}.id")
            ->select(
                DB::raw("coalesce({$contextTable}.name, ?) as label"),
                DB::raw("count({$this->ordersTable}.id) as value")
            )
            ->addBinding($emptyLabel, 'select')
            ->groupBy("{$this->ordersTable}.{$foreignKey}", "{$contextTable}.name")
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($row) => (object)[
                'color' => $color,
                'label' => $row->label,
                'value' => $row->value,
            ])
            ->toArray();
    }
}
