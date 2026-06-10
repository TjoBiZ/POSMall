<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Classes\Taxes;

use October\Rain\Database\Collection;
use October\Rain\Exception\ValidationException;
use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Classes\Taxes\UsaTaxCheckoutGuard;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\CartProduct;
use KodZero\POSMall\Models\Product;

class UsaTaxCheckoutGuardTest extends UsaTaxTestCase
{
    public function test_it_blocks_cart_product_when_state_is_not_allowed(): void
    {
        $tax = $this->tax('California Tax', 'CA', 'PHYSICAL_TPP', 7.25);
        $productId = $this->createProduct('Restricted Product');
        DB::table('kodzero_posmall_products')->where('id', $productId)->update(['sell_only_to_tax_states' => true]);
        $product = Product::find($productId);
        $product->taxes()->attach($tax->id);

        $cartProduct = new CartProduct();
        $cartProduct->setRelation('data', $product->fresh());
        $cartProduct->setRelation('service_options', new Collection());

        $cart = new Cart();
        $cart->setRelation('products', new Collection([$cartProduct]));
        $cart->setRelation('shipping_address', (object)[
            'state' => (object)['code' => 'TX'],
        ]);

        $this->expectException(ValidationException::class);

        app(UsaTaxCheckoutGuard::class)->validate($cart);
    }

    public function test_it_does_not_guess_state_code_from_state_name(): void
    {
        $tax = $this->tax('California Tax', 'CA', 'PHYSICAL_TPP', 7.25);
        $productId = $this->createProduct('Restricted Product');
        DB::table('kodzero_posmall_products')->where('id', $productId)->update(['sell_only_to_tax_states' => true]);
        $product = Product::find($productId);
        $product->taxes()->attach($tax->id);

        $cartProduct = new CartProduct();
        $cartProduct->setRelation('data', $product->fresh());
        $cartProduct->setRelation('service_options', new Collection());

        $cart = new Cart();
        $cart->setRelation('products', new Collection([$cartProduct]));
        $cart->setRelation('shipping_address', (object)[
            'state' => (object)['name' => 'Mississippi'],
        ]);

        app(UsaTaxCheckoutGuard::class)->validate($cart);

        $this->assertTrue(true);
    }
}
