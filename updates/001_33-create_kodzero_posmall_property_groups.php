<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPropertyGroups extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_property_groups', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('display_name')->nullable();
            $table->string('description')->nullable();
            $table->string('slug');
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_property_groups');
    }
}
