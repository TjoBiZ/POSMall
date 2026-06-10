<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProducts extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('category_id')->nullable();
            $table->integer('brand_id')->nullable();
            $table->string('user_defined_id')->nullable();
            $table->string('name', 255);
            $table->string('slug', 191);
            $table->string('description_short', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->text('additional_descriptions')->nullable();
            $table->text('additional_properties')->nullable();
            $table->integer('weight')->nullable();
            $table->integer('quantity_default')->nullable();
            $table->integer('quantity_min')->nullable();
            $table->integer('quantity_max')->nullable();
            $table->integer('stock')->default(0);
            $table->text('links')->nullable();
            $table->string('inventory_management_method')->default('single');
            $table->boolean('allow_out_of_stock_purchases')->default(false);
            $table->boolean('stackable')->default(true);
            $table->boolean('shippable')->default(true);
            $table->boolean('price_includes_tax')->default(true);
            $table->integer('group_by_property_id')->nullable();
            $table->boolean('published')->default(false);
            $table->integer('sales_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index('deleted_at', 'idx_kodzero_posmall_product_deleted_at');
                $table->index('slug', 'idx_kodzero_posmall_product_slug');
                $table->index('category_id', 'idx_kodzero_posmall_product_category_id');
            }
        });
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('kodzero_posmall_products');
        Schema::enableForeignKeyConstraints();
    }
}
