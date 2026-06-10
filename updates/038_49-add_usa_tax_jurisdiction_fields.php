<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddUsaTaxJurisdictionFields extends Migration
{
    public function up()
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'jurisdiction_type')) {
                    $table->string('jurisdiction_type', 30)->nullable()->index();
                }

                if (!Schema::hasColumn($tableName, 'jurisdiction_name')) {
                    $table->string('jurisdiction_name')->nullable()->index();
                }

                if (!Schema::hasColumn($tableName, 'jurisdiction_code')) {
                    $table->string('jurisdiction_code', 80)->nullable()->index();
                }

                if (!Schema::hasColumn($tableName, 'state_rate_percent')) {
                    $table->decimal('state_rate_percent', 8, 4)->nullable();
                }

                if (!Schema::hasColumn($tableName, 'local_rate_percent')) {
                    $table->decimal('local_rate_percent', 8, 4)->nullable();
                }
            });
        }
    }

    public function down()
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            foreach ([
                'jurisdiction_type',
                'jurisdiction_name',
                'jurisdiction_code',
                'state_rate_percent',
                'local_rate_percent',
            ] as $column) {
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
