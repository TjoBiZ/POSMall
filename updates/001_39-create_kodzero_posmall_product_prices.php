<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductPrices extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_product_prices', function ($table) {
            $table->increments('id');
            $table->integer('price')->nullable();
            $table->integer('product_id');
            $table->integer('variant_id')->nullable();
            $table->integer('currency_id');
            $table->timestamps();

            if (! app()->runningUnitTests()) {
                $table->unique(['product_id', 'currency_id', 'variant_id'], 'kodzero_posmall_product_price_unique_price');
                $table->index('product_id', 'idx_kodzero_posmall_product_price_product');
                $table->index('variant_id', 'idx_kodzero_posmall_product_price_variant');
                $table->index('currency_id', 'idx_kodzero_posmall_product_price_currency');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_product_prices');
    }
}
