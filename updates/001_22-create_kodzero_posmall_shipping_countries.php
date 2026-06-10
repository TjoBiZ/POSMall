<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallShippingCountries extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_shipping_countries', function ($table) {
            $table->increments('id');
            $table->integer('shipping_method_id');
            $table->integer('country_id');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_shipping_countries');
    }
}
