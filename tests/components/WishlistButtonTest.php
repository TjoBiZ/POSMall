<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Components;

use KodZero\POSMall\Components\WishlistButton;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Wishlist;
use KodZero\POSMall\Tests\PluginTestCase;

class WishlistButtonTest extends PluginTestCase
{
    public function test_add_is_idempotent_for_same_product_in_same_favorites_list(): void
    {
        $product = Product::published()->first();
        if (! $product) {
            $this->markTestSkipped('No published POSMall product exists for Favorites idempotency test.');
        }

        $component = new class extends WishlistButton {
            protected function refreshList(): array
            {
                return [];
            }
        };

        for ($i = 0; $i < 3; $i++) {
            $this->replacePost([
                'product_id' => $component->encode($product->id),
                'quantity' => 1,
            ]);

            $component->onAdd();
        }

        $wishlist = Wishlist::byUser()->first();

        $this->assertNotNull($wishlist);
        $this->assertSame(1, $wishlist->items()->count());
        $this->assertSame($product->id, $wishlist->items()->first()->product_id);
    }

    private function replacePost(array $payload): void
    {
        request()->setMethod('POST');
        request()->replace($payload);
        request()->request->replace($payload);
    }
}
