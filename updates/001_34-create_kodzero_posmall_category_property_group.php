<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCategoryPropertyGroup extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_category_property_group', function ($table) {
            $table->increments('id');
            $table->integer('category_id');
            $table->integer('property_group_id');
            $table->integer('relation_sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index(['category_id', 'property_group_id'], 'idx_kodzero_posmall_property_group_pivot');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_category_property_group');
    }
}
