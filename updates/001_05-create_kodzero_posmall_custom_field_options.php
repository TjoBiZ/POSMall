<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductCustomFieldOptions extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_custom_field_options', function ($table) {
            $table->increments('id');
            $table->integer('custom_field_id')->nullable();
            $table->string('name');
            $table->string('option_value')->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_custom_field_options');
    }
}
