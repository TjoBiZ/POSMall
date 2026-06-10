<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCategoryProductSortOrder extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_category_product_sort_order', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('category_id');
            $table->integer('sort_order');
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_category_product_sort_order');
    }
}
