<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class UpdateJsonableColumnsToText extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_payments_log', function (Blueprint $table) {
            $table->text('payment_method')->nullable()->change();
            $table->text('data')->nullable()->change();
            $table->text('order_data')->nullable()->change();
        });
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            $table->text('currency')->nullable()->change();
            $table->text('billing_address')->nullable()->change();
            $table->text('shipping_address')->nullable()->change();
            $table->text('shipping')->nullable()->change();
            $table->text('taxes')->nullable()->change();
            $table->text('payment')->nullable()->change();
            $table->text('payment_data')->nullable()->change();
        });
        Schema::table('kodzero_posmall_order_products', function (Blueprint $table) {
            $table->text('property_values')->nullable()->change();
            $table->text('custom_field_values')->change();
            $table->text('taxes')->change();
            $table->text('item')->change();
        });
    }

    public function down()
    {
        // Leave the columns. The migration might fail if data gets truncated.
    }
}
