<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallShippingMethodTax extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_shipping_method_tax', function ($table) {
            $table->increments('id');
            $table->integer('shipping_method_id');
            $table->integer('tax_id');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_shipping_method_tax');
    }
}
