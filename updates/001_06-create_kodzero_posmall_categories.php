<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCategories extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_categories', function ($table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->string('code')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('inherit_property_groups')->nullable()->default(0);
            $table->boolean('inherit_review_categories')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            // NestedTree
            $table->integer('parent_id')->nullable();
            $table->integer('nest_left')->nullable();
            $table->integer('nest_right')->nullable();
            $table->integer('nest_depth')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index('deleted_at', 'idx_kodzero_posmall_category_deleted_at');
                $table->index('parent_id', 'idx_kodzero_posmall_category_parent_id');
                $table->index('nest_left', 'idx_kodzero_posmall_category_nest_left');
                $table->index('nest_right', 'idx_kodzero_posmall_category_nest_right');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_categories');
    }
}
