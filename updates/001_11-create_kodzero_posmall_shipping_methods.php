<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallShippingMethods extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_shipping_methods', function ($table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->integer('sort_order')->nullable();
            $table->integer('guaranteed_delivery_days')->nullable();
            $table->boolean('price_includes_tax')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_shipping_methods');
    }
}
