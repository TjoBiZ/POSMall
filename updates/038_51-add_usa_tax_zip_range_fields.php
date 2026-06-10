<?php

declare(strict_types=1);

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                if (!Schema::hasColumn($table->getTable(), 'zip_code_ranges')) {
                    $table->text('zip_code_ranges')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                if (Schema::hasColumn($table->getTable(), 'zip_code_ranges')) {
                    $table->dropColumn('zip_code_ranges');
                }
            });
        }
    }
};
