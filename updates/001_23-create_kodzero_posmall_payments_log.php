<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPaymentsLog extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_payments_log', function ($table) {
            $table->increments('id');
            $table->string('reference');
            $table->boolean('failed')->default(true);
            $table->string('payment_method')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('ip')->nullable();
            $table->string('session_id')->nullable();
            $table->text('data')->nullable();
            $table->integer('order_id')->nullable();
            $table->text('order_data')->nullable();
            $table->string('message')->nullable();
            $table->string('code')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_payments_log');
    }
}
