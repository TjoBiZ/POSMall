<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCartCustomFieldValue extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_cart_custom_field_value', function ($table) {
            $table->increments('id');
            $table->integer('cart_product_id')->nullable();
            $table->integer('custom_field_id');
            $table->integer('custom_field_option_id')->nullable();
            $table->text('value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_cart_custom_field_value');
    }
}
