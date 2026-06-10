<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCustomerPaymentMethods extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_customer_payment_methods', function ($table) {
            $table->increments('id');
            $table->string('name')->nullabe();
            $table->integer('customer_id');
            $table->integer('payment_method_id');
            $table->boolean('is_default')->default(0);
            $table->text('data')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::table('kodzero_posmall_orders', function ($table) {
            $table->integer('customer_payment_method_id')->nullable();
        });
        Schema::table('kodzero_posmall_carts', function ($table) {
            $table->integer('customer_payment_method_id')->nullable();
        });
        Schema::table('kodzero_posmall_customers', function ($table) {
            $table->string('stripe_customer_id')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_customer_payment_methods');
        Schema::table('kodzero_posmall_orders', function ($table) {
            $table->dropColumn(['customer_payment_method_id']);
        });
        Schema::table('kodzero_posmall_carts', function ($table) {
            $table->dropColumn(['customer_payment_method_id']);
        });
        Schema::table('kodzero_posmall_customers', function ($table) {
            $table->dropColumn(['stripe_customer_id']);
        });
    }
}
