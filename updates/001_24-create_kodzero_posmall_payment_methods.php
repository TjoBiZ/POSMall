<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPaymentMethods extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_payment_methods', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->text('payment_provider');
            $table->integer('sort_order')->nullable();
            $table->string('fee_label')->nullable();
            $table->decimal('fee_percentage', 5, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_payment_methods');
    }
}
