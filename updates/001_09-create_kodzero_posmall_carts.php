<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCarts extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_carts', function ($table) {
            $table->increments('id');
            $table->string('session_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->integer('shipping_address_id')->nullable();
            $table->integer('billing_address_id')->nullable();
            $table->integer('shipping_method_id')->nullable();
            $table->integer('payment_method_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index('session_id', 'idx_kodzero_posmall_cart_session_id');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_carts');
    }
}
