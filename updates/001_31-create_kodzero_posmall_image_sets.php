<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallImageSets extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_image_sets', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('product_id');
            $table->boolean('is_main_set')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_image_sets');
    }
}
