<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPriceCategories extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_price_categories', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->integer('sort_order')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_price_categories');
    }
}
