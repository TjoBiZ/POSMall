<?php

declare(strict_types=1);

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kodzero_posmall_usa_tax_region_rows')) {
            return;
        }

        Schema::table('kodzero_posmall_usa_tax_region_rows', function (Blueprint $table) {
            if (!Schema::hasColumn($table->getTable(), 'tax_id')) {
                $table->integer('tax_id')->nullable()->index();
            }

            if (!Schema::hasColumn($table->getTable(), 'tax_main_group')) {
                $table->string('tax_main_group', 30)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('kodzero_posmall_usa_tax_region_rows')) {
            return;
        }

        Schema::table('kodzero_posmall_usa_tax_region_rows', function (Blueprint $table) {
            foreach (['tax_id', 'tax_main_group'] as $column) {
                if (Schema::hasColumn($table->getTable(), $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
