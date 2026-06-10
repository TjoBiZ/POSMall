<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\Order;
use Validator;

class OrderApiService
{
    public function __construct(
        private readonly OrderResource $orders
    ) {
    }

    public function list(array $input, CommerceContext $context, ?int $customerId = null): array
    {
        $customerId = $customerId ?: (int)($input['customer_id'] ?? 0);
        $this->validateCustomer($customerId, $context);

        $perPage = max(1, min(100, (int)($input['per_page'] ?? 20)));
        $page = max(1, (int)($input['page'] ?? 1));
        $query = $this->baseQuery($customerId, $context);

        if (!empty($input['payment_state'])) {
            $query->where('payment_state', (string)$input['payment_state']);
        }

        if (!empty($input['order_state_id'])) {
            $query->where('order_state_id', (int)$input['order_state_id']);
        }

        $paginator = $query->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);

        return [
            'orders' => $paginator->getCollection()->map(fn (Order $order) => $this->orders->order($order))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    public function detail(string $hash, array $input, CommerceContext $context): array
    {
        $customerId = (int)($input['customer_id'] ?? 0);
        $this->validateCustomer($customerId, $context);

        $id = (new Order())->decode($hash);
        if (!$id) {
            throw new ValidationException(['order' => 'Invalid POSMall order hash.']);
        }

        $order = $this->baseQuery($customerId, $context)->whereKey((int)$id)->firstOrFail();

        return ['order' => $this->orders->order($order)];
    }

    public function status(string $hash, array $input, CommerceContext $context): array
    {
        $order = $this->detail($hash, $input, $context)['order'];

        return [
            'order' => [
                'id' => $order['id'],
                'hash_id' => $order['hash_id'],
                'order_number' => $order['order_number'],
                'api_source' => $order['api_source'],
                'payment_state' => $order['payment_state'],
                'payment_state_label' => $order['payment_state_label'],
                'order_state' => $order['order_state'],
                'context' => $order['context'],
                'created_at' => $order['created_at'],
            ],
        ];
    }

    private function baseQuery(int $customerId, CommerceContext $context): Builder
    {
        $query = Order::with(['products', 'payment_method', 'order_state', 'vendor', 'channel', 'warehouse'])
            ->where('customer_id', $customerId);

        if ($context->vendorExplicit) {
            $query->where('vendor_id', optional($context->vendor)->id);
        }

        if ($context->channelExplicit) {
            $query->where('channel_id', optional($context->channel)->id);
        }

        if ($context->warehouseExplicit) {
            $query->where('warehouse_id', optional($context->warehouse)->id);
        }

        return $query;
    }

    private function validateCustomer(int $customerId, CommerceContext $context): void
    {
        $validator = Validator::make(['customer_id' => $customerId], [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (!$context->token->allowsCustomerId($customerId)) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }

        Customer::findOrFail($customerId);
    }
}
