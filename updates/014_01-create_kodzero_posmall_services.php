<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallServices extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_services', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('kodzero_posmall_service_options', function ($table) {
            $table->increments('id');
            $table->integer('service_id')->index()->nullable();
            $table->string('name');
            $table->string('description')->nullable();
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('kodzero_posmall_product_service', function ($table) {
            $table->increments('id');
            $table->integer('service_id');
            $table->integer('product_id');
            $table->boolean('required')->default(0);

            $table->unique(['service_id', 'product_id']);
            $table->index(['service_id', 'product_id']);
        });
        Schema::create('kodzero_posmall_cart_product_service_option', function ($table) {
            $table->increments('id');
            $table->integer('service_option_id');
            $table->integer('cart_product_id');

            $table->unique(['cart_product_id', 'service_option_id'], 'kodzero_posmall_unq_cart_product_service_option');
            $table->index(['cart_product_id', 'service_option_id'], 'idx_kodzero_posmall_cart_product_service_option');
        });
        Schema::create('kodzero_posmall_service_tax', function ($table) {
            $table->increments('id');
            $table->integer('tax_id')->index();
            $table->integer('service_id')->index();

            $table->unique(['service_id', 'tax_id']);
            $table->index(['service_id', 'tax_id']);
        });
        Schema::table('kodzero_posmall_order_products', function ($table) {
            $table->text('service_options')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_services');
        Schema::dropIfExists('kodzero_posmall_service_options');
        Schema::dropIfExists('kodzero_posmall_service_tax');
        Schema::dropIfExists('kodzero_posmall_product_service');
        Schema::dropIfExists('kodzero_posmall_cart_product_service_option');
        Schema::table('kodzero_posmall_order_products', function ($table) {
            $table->dropColumn(['service_options']);
        });
    }
}
