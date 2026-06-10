<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Auth\Access\AuthorizationException;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;
use KodZero\POSMall\Models\Wishlist;
use KodZero\POSMall\Models\WishlistItem;
use Validator;

class FavoriteListApiService
{
    public function __construct(
        private readonly ProductResource $products,
        private readonly ApiIdResolver $ids
    ) {
    }

    public function list(array $input, ?CommerceContext $context = null): array
    {
        $customer = $this->customer($input, $context);

        return [
            'favorite_lists' => Wishlist::with(['items.product', 'items.variant.product'])
                ->where('customer_id', $customer->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Wishlist $list) => $this->favoriteList($list))
                ->values()
                ->all(),
        ];
    }

    public function create(array $input, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'name' => 'required|string|max:190',
        ]);

        $this->assertCustomerAllowed((int)$input['customer_id'], $context);
        $list = Wishlist::create([
            'customer_id' => (int)$input['customer_id'],
            'name' => trim((string)$input['name']),
        ]);

        return ['favorite_list' => $this->favoriteList($list->fresh(['items.product', 'items.variant.product']))];
    }

    public function addItem(array $input, int $listId, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
            'product_id' => 'required',
            'variant_id' => 'nullable',
            'quantity' => 'nullable|integer|min:1',
        ]);

        $this->assertCustomerAllowed((int)$input['customer_id'], $context);
        $list = $this->ownedList((int)$input['customer_id'], $listId);
        $productId = $this->ids->modelId((string)$input['product_id'], 'product');
        $variantId = !empty($input['variant_id'])
            ? $this->ids->modelId((string)$input['variant_id'], 'variant')
            : null;

        $product = Product::published()->withoutServiceCarriers()->findOrFail($productId);
        if ($variantId !== null) {
            Variant::published()->where('product_id', $product->id)->findOrFail($variantId);
        }

        $item = WishlistItem::firstOrNew([
            'wishlist_id' => $list->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
        ]);
        $item->quantity = max(1, (int)($input['quantity'] ?? $item->quantity ?: 1));
        $item->save();

        return ['favorite_list' => $this->favoriteList($list->fresh(['items.product', 'items.variant.product']))];
    }

    public function removeItem(array $input, int $listId, int $itemId, ?CommerceContext $context = null): array
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
        ]);

        $this->assertCustomerAllowed((int)$input['customer_id'], $context);
        $list = $this->ownedList((int)$input['customer_id'], $listId);
        $item = $list->items->firstWhere('id', $itemId);

        if (!$item) {
            throw new ValidationException(['item_id' => 'Favorite List item does not belong to this customer.']);
        }

        $item->delete();

        return ['favorite_list' => $this->favoriteList($list->fresh(['items.product', 'items.variant.product']))];
    }

    private function favoriteList(Wishlist $list): array
    {
        $list->loadMissing(['items.product', 'items.variant']);

        return [
            'id' => (int)$list->id,
            'hash_id' => $list->hash_id,
            'name' => (string)$list->name,
            'items_count' => $list->items->count(),
            'items' => $list->items->map(fn (WishlistItem $item) => [
                'id' => (int)$item->id,
                'hash_id' => $item->hash_id,
                'quantity' => (int)$item->quantity,
                'item' => $item->variant
                    ? $this->products->listingItem($item->variant)
                    : $this->products->listingItem($item->product),
            ])->values()->all(),
        ];
    }

    private function customer(array $input, ?CommerceContext $context = null): Customer
    {
        $this->validate($input, [
            'customer_id' => 'required|integer|exists:kodzero_posmall_customers,id',
        ]);

        $this->assertCustomerAllowed((int)$input['customer_id'], $context);

        return Customer::findOrFail((int)$input['customer_id']);
    }

    private function ownedList(int $customerId, int $listId): Wishlist
    {
        return Wishlist::with(['items.product', 'items.variant.product'])
            ->where('customer_id', $customerId)
            ->findOrFail($listId);
    }

    private function validate(array $input, array $rules): void
    {
        $validator = Validator::make($input, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function assertCustomerAllowed(int $customerId, ?CommerceContext $context): void
    {
        if ($context && !$context->token->allowsCustomerId($customerId)) {
            throw new AuthorizationException('The POSMall API token is not allowed to access this customer.');
        }
    }
}
