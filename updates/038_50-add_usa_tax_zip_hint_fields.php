<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddUsaTaxZipHintFields extends Migration
{
    public function up()
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'zip_code_hints')) {
                    $table->text('zip_code_hints')->nullable();
                }

                if (!Schema::hasColumn($tableName, 'boundary_source_url')) {
                    $table->text('boundary_source_url')->nullable();
                }
            });
        }
    }

    public function down()
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            foreach (['zip_code_hints', 'boundary_source_url'] as $column) {
                if (!Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
