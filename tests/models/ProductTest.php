<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests\Models;

use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Tests\PluginTestCase;

class ProductTest extends PluginTestCase
{
    /**
     * Setup the test environment.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test custom field relationship
     * @return void
     */
    public function test_custom_field_relationship()
    {
        $product = Product::first();
        $field   = CustomField::first();

        $product->custom_fields()->save($field);

        $this->assertEquals('Test', $product->fresh()->custom_fields->first()->name);
    }

    /**
     * Test price accessors
     * @return void
     */
    public function test_price_accessors()
    {
        $price          = ['CHF' => 20.50, 'EUR' => 80.50];
        $priceFormatted = ['CHF' => 'CHF 20.50', 'EUR' => '80.50€'];

        $product        = Product::orderBy('id')->first();
        $product->save();
        $product->price = $price;

        $product        = $product->fresh('prices.currency');

        $this->assertEquals($priceFormatted['CHF'], $product->price()->string);
        $this->assertEquals(80.50, $product->price('EUR')->decimal);
        $this->assertEquals(20.50, $product->price()->decimal);
        $this->assertEquals(2050, $product->price('CHF')->integer);
    }

    public function test_youtube_video_id_extraction()
    {
        $this->assertSame('TRjsqKnScH0', Product::extractYoutubeVideoId('https://www.youtube.com/watch?v=TRjsqKnScH0&feature=share'));
        $this->assertSame('eqqcug--QFM', Product::extractYoutubeVideoId('https://youtu.be/eqqcug--QFM'));
        $this->assertSame('eYNsGOXyW_E', Product::extractYoutubeVideoId('https://www.youtube-nocookie.com/embed/eYNsGOXyW_E'));
        $this->assertSame('nvEXpGCqoHw', Product::extractYoutubeVideoId('[video type="youtube" clip_id="nvEXpGCqoHw" width="1280"]'));
        $this->assertNull(Product::extractYoutubeVideoId('<script>alert(1)</script>'));
    }
}
