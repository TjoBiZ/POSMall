<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Illuminate\Support\Collection;
use October\Rain\Exception\ValidationException;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Variant;
use KodZero\POSMall\Models\Wishlist;
use Validator;

class WishlistButton extends POSMallComponent
{
    use HashIds;

    /**
     * All wishlists of this user.
     *
     * @var Collection<Wishlist>
     */
    public $items;

    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.wishlistButton.details.name',
            'description' => 'kodzero.posmall::lang.components.wishlistButton.details.description',
        ];
    }

    public function defineProperties()
    {
        return [
            'product' => [
                'title'       => 'kodzero.posmall::lang.components.wishlistButton.properties.product.title',
                'description' => 'kodzero.posmall::lang.components.wishlistButton.properties.product.description',
                'type'        => 'string',
            ],
            'variant' => [
                'title'       => 'kodzero.posmall::lang.components.wishlistButton.properties.variant.title',
                'description' => 'kodzero.posmall::lang.components.wishlistButton.properties.variant.description',
                'type'        => 'string',
            ],
        ];
    }

    public function init()
    {
        $this->items = $this->page['items'] = $this->getWishlists();
    }

    /**
     * A product is added to a wishlist.
     *
     * @throws ValidationException
     */
    public function onAdd()
    {
        $v = Validator::make(post(), [
            'product_id' => 'required',
            'quantity'   => 'nullable|int',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $wishlists = $this->getWishlists();

        // If there is no wishlist available create the initial one.
        if ($wishlists->count() < 1) {
            $wishlists = collect([Wishlist::createForUser(Auth::user())]);
        }

        $wishlist = post('wishlist_id')
            ? $wishlists->where('id', $this->decode(post('wishlist_id')))->first()
            : $wishlists->first();

        if (! $wishlist) {
            throw new ValidationException(['wishlist_id' => 'Invalid list ID provided.']);
        }

        [$productId, $variantId] = $this->decodeIds();

        $product = Product::published()->where('id', $productId)->first();

        if (! $product) {
            throw new ValidationException(['product_id' => 'Invalid product ID provided.']);
        }

        if ($variantId) {
            $variantExists = Variant::published()
                ->where('product_id', $product->id)
                ->where('id', $variantId)
                ->exists();

            if (! $variantExists) {
                throw new ValidationException(['variant_id' => 'Invalid variant ID provided.']);
            }
        }

        $quantity = max(1, min(1000, (int)post('quantity', 1)));

        $wishlist->items()->firstOrCreate(
            [
                'product_id' => $productId,
                'variant_id' => $variantId,
            ],
            ['quantity' => $quantity]
        );

        Flash::success(trans('kodzero.posmall::frontend.wishlist.added'));

        return $this->refreshList();
    }

    /**
     * A new wishlist is being created.
     */
    public function onCreate()
    {
        $v = Validator::make(post(), [
            'name' => 'required|max:190',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $this->decodeIds();

        Wishlist::createForUser(Auth::user(), post('name'));

        return $this->refreshList();
    }

    /**
     * A wishlist is being deleted.
     */
    public function onDelete()
    {
        $v = Validator::make(post(), [
            'wishlist_id' => 'required',
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $wishlistId = $this->decode(post('wishlist_id'));
        $wishlist   = $this->getWishlists()->where('id', $wishlistId)->first();

        if (! $wishlist) {
            throw new ValidationException(['wishlist_id' => 'Invalid wishlist ID provided.']);
        }

        $wishlist->delete();

        return $this->refreshList();
    }

    /**
     * Fetches all wishlists of the currently logged in user
     * or the cart session.
     */
    public function getWishlists()
    {
        return Wishlist::byUser(Auth::user());
    }

    /**
     * Re-render the list partial.
     *
     * @return array
     */
    protected function refreshList(): array
    {
        $items = $this->getWishlists();
        $this->page['items'] = $items;

        return [
            '.mall-wishlists' => $this->renderPartial($this->alias . '::list', ['items' => $items]),
            'favorite_items_quantity' => $this->favoriteItemsQuantity($items),
        ];
    }

    /**
     * @return array
     */
    protected function decodeIds(): array
    {
        $productId = $this->decode(post('product_id'));
        $variantId = post('variant_id') ? $this->decode(post('variant_id')) : null;

        $this->setProperty('product', $productId);
        $this->setProperty('variant', $variantId);

        return [$productId, $variantId];
    }

    protected function favoriteItemsQuantity(Collection $items): int
    {
        return (int)$items->sum(fn (Wishlist $wishlist) => $wishlist->items->count());
    }
}
