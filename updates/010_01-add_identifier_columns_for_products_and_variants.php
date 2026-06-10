<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddIdentifierColumnsForProductsAndVariants extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_products', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_products', 'mpn')) {
                $table->string('mpn')->nullable();
            }
            if (! Schema::hasColumn('kodzero_posmall_products', 'gtin')) {
                $table->string('gtin')->nullable();
            }
        });
        Schema::table('kodzero_posmall_product_variants', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_product_variants', 'mpn')) {
                $table->string('mpn')->nullable();
            }
            if (! Schema::hasColumn('kodzero_posmall_product_variants', 'gtin')) {
                $table->string('gtin')->nullable();
            }
        });
    }

    public function down()
    {
        $this->dropColumnsIfExist('kodzero_posmall_products', ['mpn', 'gtin']);
        $this->dropColumnsIfExist('kodzero_posmall_product_variants', ['mpn', 'gtin']);
    }

    protected function dropColumnsIfExist(string $tableName, array $columns): void
    {
        $existing = array_filter($columns, fn ($column) => Schema::hasColumn($tableName, $column));

        if (!$existing) {
            return;
        }

        Schema::table($tableName, function ($table) use ($existing) {
            $table->dropColumn(array_values($existing));
        });
    }
}
