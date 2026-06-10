<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductVariants extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_product_variants', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->string('user_defined_id')->nullable();
            $table->integer('image_set_id')->nullable();
            $table->integer('stock')->default(0);
            $table->string('name')->nullable();
            $table->integer('weight')->nullable();
            $table->boolean('published')->default(true);
            $table->integer('sales_count')->default(0);
            $table->boolean('allow_out_of_stock_purchases')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index('product_id', 'idx_kodzero_posmall_product_variant_product_id');
            }
        });
    }

    public function down()
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('kodzero_posmall_product_variants');
        Schema::enableForeignKeyConstraints();
    }
}
