<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCartDiscount extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_cart_discount', function ($table) {
            $table->increments('id');
            $table->integer('cart_id');
            $table->integer('discount_id');

            if (! app()->runningUnitTests()) {
                $table->index(['cart_id', 'discount_id'], 'idx_kodzero_posmall_cart_discount_pivot');
            }
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_cart_discount');
    }
}
