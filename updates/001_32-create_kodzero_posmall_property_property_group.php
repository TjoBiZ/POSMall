<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPropertyPropertyGroup extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_property_property_group', function ($table) {
            $table->increments('id');
            $table->integer('property_id');
            $table->integer('property_group_id');
            $table->boolean('use_for_variants')->default(false);
            $table->string('filter_type')->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_property_property_group');
    }
}
