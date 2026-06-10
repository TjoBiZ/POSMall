<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductCustomFields extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_custom_fields', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('type')->default('text');
            $table->boolean('required')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_custom_fields');
    }
}
