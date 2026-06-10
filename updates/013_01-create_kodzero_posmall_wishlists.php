<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallWishlists extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_wishlists', function ($table) {
            $table->increments('id');
            $table->string('name');

            $table->string('session_id')->nullable()->index();
            $table->integer('customer_id')->nullable()->index();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_wishlists');
    }
}
