<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Cms\Classes\Theme;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redirect;
use October\Rain\Exception\ValidationException;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Classes\Traits\HashIds;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Wishlist;
use KodZero\POSMall\Models\WishlistItem;
use Validator;

class Wishlists extends POSMallComponent
{
    use HashIds;

    /**
     * All wishlists of this user.
     *
     * @var Collection<Wishlist>
     */
    public $items;

    /**
     * Default minimum quantity.
     *
     * @var int
     */
    public $defaultMinQuantity = 1;

    /**
     * Default maximum quantity.
     *
     * @var int
     */
    public $defaultMaxQuantity = 100;

    /**
     * Display the DiscountApplier component.
     *
     * @var bool
     */
    public $showDiscountApplier = true;

    /**
     * Display a tax summary at the end of the cart.
     *
     * @var bool
     */
    public $showTaxes = true;

    /**
     * The currently displayed wishlist.
     *
     * @var Wishlist
     */
    public $currentItem;

    /**
     * True when the account page displays all favorite items.
     *
     * @var bool
     */
    public $showingAllItems = false;

    /**
     * Deduplicated favorite items across all lists.
     *
     * @var Collection<WishlistItem>
     */
    public $allFavoriteItems;

    /**
     * True if at least one wishlist has at least one item.
     *
     * @var bool
     */
    public $hasItems = false;

    /**
     * PDF download is available.
     *
     * @var bool
     */
    public $allowPDFDownload = false;

    /**
     * Show shipping method selector.
     *
     * @var bool
     */
    public $showShipping = false;

    /**
     * All available shipping methods.
     *
     * @var Collection<ShippingMethod>|ShippingMethod[]
     */
    public $shippingMethods;

    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.wishlists.details.name',
            'description' => 'kodzero.posmall::lang.components.wishlists.details.description',
        ];
    }

    public function defineProperties()
    {
        return [
            'showShipping' => [
                'title'       => 'kodzero.posmall::lang.components.wishlists.properties.showShipping.title',
                'description' => 'kodzero.posmall::lang.components.wishlists.properties.showShipping.description',
                'type'        => 'checkbox',
            ],
        ];
    }

    public function init()
    {
        $this->allowPDFDownload = $this->pdfPartialExists();
    }

    public function onRun()
    {
        $this->setVar('productPage', GeneralSettings::get('product_page'));

        if ($this->allowPDFDownload && $download = input('download')) {
            return $this->handlePDFDownload($download);
        }

        /** @var Collection<Wishlist>|Wishlist[] items */
        /** @var Wishlist currentItem */
        $this->items       = $this->getWishlists();
        $this->currentItem = $this->items->where('id', $this->decode($this->param('id') ?? ''))->first() ?: $this->items->first();
        $this->allFavoriteItems = $this->getAllFavoriteItems($this->items);

        $this->handleShipping();

        $this->hasItems = $this->items->contains(fn ($item) => $item->items->count() > 0);
    }

    public function onSelect()
    {
        $this->setCurrentItem();

        return $this->refreshContent();
    }

    public function onSelectAll()
    {
        $this->items = $this->getWishlists();
        $this->currentItem = null;
        $this->showingAllItems = true;
        $this->allFavoriteItems = $this->getAllFavoriteItems($this->items);

        return $this->refreshContent();
    }

    public function onRename()
    {
        $this->setCurrentItem();

        $validation = Validator::make(post() ?: [], [
            'name' => 'required|max:190',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $this->currentItem->name = post('name');
        $this->currentItem->save();

        Flash::success(trans('kodzero.posmall::frontend.wishlist.renamed'));

        return $this->refreshList();
    }

    public function onRemove()
    {
        $this->setCurrentItem();
        $this->currentWishlistItem()->delete();
        $this->setCurrentItem();

        return $this->refreshListAndContent();
    }

    public function onUpdateQuantity()
    {
        $this->setCurrentItem();

        $validation = Validator::make(post() ?: [], [
            'quantity' => 'bail|required|integer|min:1|max:1000',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $quantity = (int)post('quantity', 1);

        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($quantity > 1000) {
            $quantity = 1000;
        }

        $item = $this->currentWishlistItem();
        $item->quantity = $quantity;
        $item->save();

        $this->setCurrentItem();

        return $this->refreshListAndContent();
    }

    public function onChangeShippingMethod()
    {
        $this->setCurrentItem();

        $method = post('shipping_method_id');

        $validation = Validator::make(post() ?: [], [
            'shipping_method_id' => 'bail|required|integer|exists:kodzero_posmall_shipping_methods,id',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        if (! $method || ! $this->shippingMethods || ! $this->shippingMethods->contains('id', (int)$method)) {
            return $this->controller->run('404');
        }

        $this->currentItem->setShippingMethod(ShippingMethod::where('id', $method)->first());

        $this->setCurrentItem();

        return $this->refreshListAndContent();
    }

    public function onClear()
    {
        $this->setCurrentItem();
        $this->currentItem->items()->delete();
        $this->setCurrentItem();

        return $this->refreshListAndContent();
    }

    public function onDelete()
    {
        $this->setCurrentItem();

        $this->currentItem->delete();

        Flash::success(trans('kodzero.posmall::frontend.wishlist.deleted'));

        // Set the current item to the next available record.
        $this->items       = $this->getWishlists();
        $this->currentItem = $this->items->first();

        return $this->refreshListAndContent();
    }

    public function onAddToCart()
    {
        $this->setCurrentItem();

        $allInStock = $this->currentItem->addToCart(Cart::byUser(Auth::user()));

        if (! $allInStock) {
            Flash::warning(trans('kodzero.posmall::frontend.wishlists.stockmissing'));
        } else {
            Flash::success(trans('kodzero.posmall::frontend.wishlists.addedtocart'));
        }

        // redirect to the cart page.
        $cartPage = GeneralSettings::get('cart_page');

        return Redirect::to($this->controller->pageUrl($cartPage));
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
     * Return the wishlist as a PDF.
     *
     * @param string $download
     *
     * @return \Illuminate\Http\Response|string
     */
    protected function handlePDFDownload(string $download)
    {
        $id        = $this->decode($download);
        $wishlists = Wishlist::byUser(Auth::user());

        /** @var Wishlist $wishlist */
        $wishlist = $wishlists->where('id', $id)->first();

        if (! $wishlist) {
            return $this->controller->run('404');
        }

        return $wishlist->getPDF()->stream(sprintf('wishlist-%s.pdf', $download));
    }

    /**
     * Handle the display of shipping methods.
     */
    protected function handleShipping()
    {
        $this->setVar('showShipping', (bool)$this->property('showShipping'));

        if (! $this->showShipping || !$this->currentItem) {
            return;
        }

        $this->shippingMethods = ShippingMethod::getAvailableByWishlist($this->currentItem);

        if ($this->currentItem->shipping_method_id === null) {
            $this->currentItem->setShippingMethod(ShippingMethod::getDefault());
            $this->currentItem = $this->currentItem->fresh('shipping_method');
        }

        return $this->currentItem->validateShippingMethod();
    }

    /**
     * Set the currently active item.
     *
     * @throws ValidationException
     */
    protected function setCurrentItem(): void
    {
        $this->items       = $this->getWishlists();
        $this->currentItem = $this->items->where('id', $this->decode(post('id')))->first();

        if (! $this->currentItem) {
            throw new ValidationException(['id' => 'Invalid wishlist ID specified']);
        }

        $this->handleShipping();
    }

    protected function currentWishlistItem(): WishlistItem
    {
        $item = $this->currentItem
            ->items()
            ->where('id', $this->decode(post('item_id')))
            ->first();

        if (! $item) {
            throw new ValidationException(['item_id' => 'Invalid wishlist item ID specified']);
        }

        return $item;
    }

    protected function refreshListAndContent(): array
    {
        return array_merge($this->refreshList(), $this->refreshContent());
    }

    protected function refreshContent(): array
    {
        $this->setVar('productPage', GeneralSettings::get('product_page'));
        $this->allFavoriteItems = $this->getAllFavoriteItems($this->items);

        if ($this->showingAllItems) {
            return [
                '.mall-wishlist-content' => $this->renderPartial(
                    $this->alias . '::contents',
                    [
                        'item' => null,
                        'allItems' => $this->allFavoriteItems,
                    ]
                ),
                'favorite_items_quantity' => $this->favoriteItemsQuantity($this->items),
            ];
        }

        if (! $this->currentItem) {
            return [
                '.mall-wishlist-content' => $this->renderPartial($this->alias . '::empty'),
                'favorite_items_quantity' => $this->favoriteItemsQuantity($this->items),
            ];
        }

        return [
            '.mall-wishlist-content' => $this->renderPartial(
                $this->alias . '::contents',
                ['item' => $this->currentItem]
            ),
            'favorite_items_quantity' => $this->favoriteItemsQuantity($this->items),
        ];
    }

    protected function refreshList(): array
    {
        $this->allFavoriteItems = $this->getAllFavoriteItems($this->items);

        if ($this->items->count() < 1) {
            return [
                '.mall-wishlists' => $this->renderPartial($this->alias . '::empty'),
                'favorite_items_quantity' => 0,
            ];
        }

        return [
            '.mall-wishlists' => $this->renderPartial(
                $this->alias . '::list',
                ['items' => $this->items]
            ),
            'favorite_items_quantity' => $this->favoriteItemsQuantity($this->items),
        ];
    }

    protected function favoriteItemsQuantity(Collection $items): int
    {
        return (int)$items->sum(fn (Wishlist $wishlist) => $wishlist->items->count());
    }

    protected function getAllFavoriteItems(Collection $items): Collection
    {
        return $items
            ->flatMap(fn (Wishlist $wishlist) => $wishlist->items->map(function (WishlistItem $item) use ($wishlist) {
                $item->setAttribute('source_wishlist_hash_id', $wishlist->hashId);

                return $item;
            }))
            ->unique(fn (WishlistItem $item) => implode(':', [
                $item->product_id,
                $item->variant_id ?: 0,
            ]))
            ->values();
    }

    /**
     * Check if the required PDF partial exists.
     * @return bool
     */
    private function pdfPartialExists()
    {
        return file_exists(
            themes_path(
                sprintf('%s/partials/posmallPDF/wishlist/default.htm', Theme::getActiveThemeCode())
            )
        );
    }
}
