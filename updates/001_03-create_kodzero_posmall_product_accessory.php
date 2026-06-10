<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductAccessory extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_product_accessory', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('accessory_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_product_accessory');
    }
}
