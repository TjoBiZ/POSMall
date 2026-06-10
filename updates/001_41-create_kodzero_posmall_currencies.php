<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCurrencies extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_currencies', function ($table) {
            $table->increments('id');
            $table->string('code');
            $table->string('symbol')->nullable();
            $table->decimal('rate', 12, 4)->default(1);
            $table->integer('decimals')->default(2);
            $table->text('format');
            $table->integer('sort_order')->nullable();
            $table->boolean('is_default')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_currencies');
    }
}
