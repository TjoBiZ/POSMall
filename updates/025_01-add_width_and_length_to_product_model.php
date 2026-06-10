<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddWidthAndLengthToProductModel extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_products', function ($table) {
            $table->integer('length')->after('weight')->nullable();
            $table->integer('width')->after('length')->nullable();
            $table->integer('height')->after('width')->nullable();
        });
        Schema::table('kodzero_posmall_product_variants', function ($table) {
            $table->integer('length')->after('weight')->nullable();
            $table->integer('width')->after('length')->nullable();
            $table->integer('height')->after('width')->nullable();
        });
    }

    public function down()
    {
        $productColumns = array_filter(['length', 'width', 'height'], fn ($column) => Schema::hasColumn('kodzero_posmall_products', $column));

        if ($productColumns) {
            Schema::table('kodzero_posmall_products', function ($table) use ($productColumns) {
                $table->dropColumn($productColumns);
            });
        }

        $variantColumns = array_filter(['length', 'width', 'height'], fn ($column) => Schema::hasColumn('kodzero_posmall_product_variants', $column));

        if ($variantColumns) {
            Schema::table('kodzero_posmall_product_variants', function ($table) use ($variantColumns) {
                $table->dropColumn($variantColumns);
            });
        }
    }
}
