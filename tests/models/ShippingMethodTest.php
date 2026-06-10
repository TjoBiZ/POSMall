<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Models;

use Event;
use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Price;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Tests\PluginTestCase;
use RainLab\Location\Models\Country;

class ShippingMethodTest extends PluginTestCase
{
    /**
     * Setup the test environment.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        DB::table('kodzero_posmall_shipping_methods')->truncate();

        // Set Country
        Event::listen('posmall.cart.setCountry', function ($model) {
            $model->countryId = 14;
        });
    }

    /**
     * Test if only enabled shipping methods are returned.
     * @return void
     */
    public function test_return_only_enabled_shipping_methods()
    {
        $method = ShippingMethod::create([
            'name'          => trans('kodzero.posmall::demo.shipping_methods.standard'),
            'sort_order'    => 1,
            'is_default'    => true,
        ]);
        $method->prices()->saveMany([
            new Price([
                'price'          => 10,
                'currency_id'    => $this->currencyId('CHF'),
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
            new Price([
                'price'          => 12,
                'currency_id'    => $this->currencyId('CHF'),
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
            new Price([
                'price'          => 15,
                'currency_id'    => 3,
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
        ]);
        
        $method = ShippingMethod::create([
            'name'                      => trans('kodzero.posmall::demo.shipping_methods.express'),
            'sort_order'                => 1,
            'is_default'                => false,
            'guaranteed_delivery_days'  => 3,
        ]);
        $method->prices()->saveMany([
            new Price([
                'price'          => 20,
                'currency_id'    => $this->currencyId('CHF'),
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
            new Price([
                'price'          => 24,
                'currency_id'    => $this->currencyId('CHF'),
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
            new Price([
                'price'          => 30,
                'currency_id'    => 3,
                'priceable_type' => ShippingMethod::MORPH_KEY,
            ]),
        ]);

        $expressMethod = ShippingMethod::first();
        $expressMethod->is_enabled = false;
        $expressMethod->save();

        $methods = ShippingMethod::get()->map(fn ($method) => $method->name)->toArray();
        $this->assertEquals($methods, ['Express']);
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_returns_available_by_min_total()
    {
        $product = $this->getProduct(99);

        $cart = new Cart();
        $cart->addProduct($product, 1);

        $availableMethod = $this->getMethod();
        $availableMethod->available_below_totals()->save(new Price([
            'price'       => 100,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_below_totals',
        ]));

        $unavailableMethod = $this->getMethod();
        $unavailableMethod->available_below_totals()->save(new Price([
            'price'       => 50,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_below_totals',
        ]));

        $available = ShippingMethod::getAvailableByCart($cart);

        $this->assertCount(1, $available);
        $this->assertEquals($availableMethod->id, $available->first()->id);
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_returns_available_by_max_total()
    {
        $product = $this->getProduct(100);

        $cart = new Cart();
        $cart->addProduct($product, 1);

        $availableMethod = $this->getMethod();
        $availableMethod->available_above_totals()->save(new Price([
            'price'       => 100,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_above_totals',
        ]));

        $unavailableMethod = $this->getMethod();
        $unavailableMethod->available_above_totals()->save(new Price([
            'price'       => 200,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_above_totals',
        ]));

        $available = ShippingMethod::getAvailableByCart($cart);

        $this->assertCount(1, $available);
        $this->assertEquals($availableMethod->id, $available->first()->id);
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_returns_available_by_min_and_max_total()
    {
        $product = $this->getProduct(100);

        $cart = new Cart();
        $cart->addProduct($product, 1);

        $availableMethod = $this->getMethod();
        $availableMethod->available_below_totals()->save(new Price([
            'price'       => 200,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_below_totals',
        ]));
        $availableMethod->available_above_totals()->save(new Price([
            'price'       => 50,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_above_totals',
        ]));

        $unavailableMethod = $this->getMethod();
        $unavailableMethod->available_below_totals()->save(new Price([
            'price'       => 120,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_below_totals',
        ]));
        $unavailableMethod->available_above_totals()->save(new Price([
            'price'       => 110,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'available_above_totals',
        ]));

        $available = ShippingMethod::getAvailableByCart($cart);

        $this->assertCount(1, $available);
        $this->assertEquals($availableMethod->id, $available->first()->id);
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_returns_available_by_destination_country()
    {
        $product = $this->getProduct(100);

        $address              = new Address();
        $address->name        = 'Mr. Miller';
        $address->lines       = 'Street 12';
        $address->zip         = '6003';
        $address->city        = 'Lucerne';
        $address->customer_id = 1;
        $address->country_id  = Country::where('code', 'CH')->first()->id;
        $address->save();

        $cart = new Cart();
        $cart->addProduct($product, 1);
        $cart->setShippingAddress($address);

        $availableMethod = $this->getMethod();
        $availableMethod->countries()->attach(Country::whereIn('code', ['CH', 'DE', 'AT'])->get());
        $availableMethod->save();

        $unavailableMethod = $this->getMethod();
        $unavailableMethod->countries()->attach(Country::whereIn('code', ['DE', 'AT'])->get());
        $unavailableMethod->save();

        $available = ShippingMethod::getAvailableByCart($cart);

        $this->assertCount(1, $available);
        $this->assertEquals($availableMethod->id, $available->first()->id);
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_calculates_price_exclusive_tax()
    {
        $product = $this->getProduct(100);

        $address              = new Address();
        $address->name        = 'Mr. Miller';
        $address->lines       = 'Street 12';
        $address->zip         = '6003';
        $address->city        = 'Lucerne';
        $address->customer_id = 1;
        $address->country_id  = Country::where('code', 'CH')->first()->id;
        $address->save();

        $cart = new Cart();
        $cart->addProduct($product, 1);
        $cart->setShippingAddress($address);

        \DB::table('kodzero_posmall_prices')->where('priceable_type', ShippingMethod::MORPH_KEY)->delete();

        $method                     = new ShippingMethod();
        $method->name               = 'Test';
        $method->price_includes_tax = false;
        $method->save();
        $method->prices()->save(new Price([
            'price'       => 100,
            'currency_id' => $this->currencyId('CHF'),
        ]));
        $method->taxes()->save(new Tax([
            'name'       => 'Shipping Tax 1',
            'percentage' => 5,
        ]));
        $method->taxes()->save(new Tax([
            'name'       => 'Shipping Tax 2',
            'percentage' => 5,
        ]));

        $cart->setShippingMethod($method);
        $this->assertEquals(10000, $cart->totals()->shippingTotal()->totalPreTaxes());
        $this->assertEquals(1000, $cart->totals()->shippingTotal()->totalTaxes());
        $this->assertEquals(11000, $cart->totals()->shippingTotal()->totalPostTaxes());

        $this->assertEquals(500, $cart->totals()->taxes()->first()->total());
    }

    /**
     * Undocumented function
     * @return void
     */
    public function test_it_calculates_price_inclusive_tax()
    {
        $product = $this->getProduct(100);

        $address              = new Address();
        $address->name        = 'Mr. Miller';
        $address->lines       = 'Street 12';
        $address->zip         = '6003';
        $address->city        = 'Lucerne';
        $address->customer_id = 1;
        $address->country_id  = Country::where('code', 'CH')->first()->id;
        $address->save();

        $cart = new Cart();
        $cart->addProduct($product, 1);
        $cart->setShippingAddress($address);

        \DB::table('kodzero_posmall_prices')->where('priceable_type', ShippingMethod::MORPH_KEY)->delete();

        $method                     = new ShippingMethod();
        $method->name               = 'Test';
        $method->price_includes_tax = true;
        $method->save();
        $method->prices()->save(new Price([
            'price'       => 100,
            'currency_id' => $this->currencyId('CHF'),
        ]));
        $method->taxes()->save(new Tax([
            'name'       => 'Shipping Tax 1',
            'percentage' => 5,
        ]));
        $method->taxes()->save(new Tax([
            'name'       => 'Shipping Tax 2',
            'percentage' => 5,
        ]));

        $cart->setShippingMethod($method);
        $this->assertEquals(9090, (int)$cart->totals()->shippingTotal()->totalPreTaxes());
        $this->assertEquals(909, (int)$cart->totals()->shippingTotal()->totalTaxes());
        $this->assertEquals(10000, (int)$cart->totals()->shippingTotal()->totalPostTaxes());
    }

    public function test_get_default_uses_stable_sort_order_fallback(): void
    {
        $second = ShippingMethod::create([
            'name' => 'Second fallback',
            'sort_order' => 20,
        ]);
        $first = ShippingMethod::create([
            'name' => 'First fallback',
            'sort_order' => 10,
        ]);

        $this->assertSame($first->id, ShippingMethod::getDefault()->id);
        $this->assertNotSame($second->id, ShippingMethod::getDefault()->id);
    }

    /**
     * Create default shipping method
     * @return ShippingMethod
     */
    protected function getMethod(): ShippingMethod
    {
        $availableMethod = new ShippingMethod();
        $availableMethod->name = 'Available';
        $availableMethod->sort_order = 1;
        $availableMethod->save();
        $availableMethod->prices()->save(new Price([
            'price'       => 100,
            'currency_id' => $this->currencyId('CHF'),
            'field'       => 'price',
        ]));

        return $availableMethod;
    }

    /**
     * Get product with and adjusted price.
     * @return mixed
     */
    protected function getProduct(int $price): Product
    {
        $product = Product::orderBy('id')->first();
        $product->save();
        $product->price = ['CHF' => $price, 'EUR' => 150];

        return $product->fresh('prices.currency');
    }
}
