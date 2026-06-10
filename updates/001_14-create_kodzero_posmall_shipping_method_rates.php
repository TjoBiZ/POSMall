<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallShippingMethodRates extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_shipping_method_rates', function ($table) {
            $table->increments('id');
            $table->integer('shipping_method_id');
            $table->integer('from_weight')->default(0);
            $table->integer('to_weight')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_shipping_method_rates');
    }
}
