<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCartProducts extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_cart_products', function ($table) {
            $table->increments('id');
            $table->integer('cart_id')->nullable();
            $table->integer('product_id');
            $table->integer('variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('weight')->nullable();
            $table->text('price');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_cart_products');
    }
}
