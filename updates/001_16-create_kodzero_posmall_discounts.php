<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallDiscounts extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_discounts', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->integer('product_id')->nullable();
            $table->string('type')->default('Rate');
            $table->string('trigger')->default('Code');
            $table->integer('rate')->nullable();
            $table->integer('max_number_of_usages')->nullable();
            $table->dateTime('expires')->nullable();
            $table->integer('number_of_usages')->default(0);
            $table->string('shipping_description')->nullable();
            $table->integer('shipping_guaranteed_days_to_delivery')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_discounts');
    }
}
