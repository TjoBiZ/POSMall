<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductCustomField extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_product_custom_field', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('custom_field_id');
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_product_custom_field');
    }
}
