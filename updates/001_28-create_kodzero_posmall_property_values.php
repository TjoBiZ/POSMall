<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPropertyValues extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_property_values', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('variant_id')->nullable();
            $table->integer('property_id');
            $table->text('value')->nullable();
            $table->text('index_value')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index(['product_id', 'variant_id'], 'idx_kodzero_posmall_property_value_product_variant');
                $table->index('product_id', 'idx_kodzero_posmall_property_value_product');
                $table->index('variant_id', 'idx_kodzero_posmall_property_value_variant');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_property_values');
    }
}
