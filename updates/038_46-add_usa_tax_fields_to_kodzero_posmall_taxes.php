<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddUsaTaxFieldsToKodZeroPOSMallTaxes extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            if (!Schema::hasColumn('kodzero_posmall_taxes', 'state_code')) {
                $table->string('state_code', 2)->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'rate_percent')) {
                $table->decimal('rate_percent', 8, 4)->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'tax_group_code')) {
                $table->string('tax_group_code', 80)->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'tax_group_name')) {
                $table->string('tax_group_name')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'tax_group_description')) {
                $table->text('tax_group_description')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'description')) {
                $table->text('description')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'is_active')) {
                $table->boolean('is_active')->default(true)->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'source_url')) {
                $table->text('source_url')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'source_type')) {
                $table->string('source_type', 30)->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'source_name')) {
                $table->string('source_name')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'parser_name')) {
                $table->string('parser_name')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'source_hash')) {
                $table->string('source_hash', 64)->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'effective_from')) {
                $table->date('effective_from')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'effective_to')) {
                $table->date('effective_to')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_taxes', 'imported_at')) {
                $table->timestamp('imported_at')->nullable();
            }
        });
    }

    public function down()
    {
        $columns = [
            'state_code',
            'rate_percent',
            'tax_group_code',
            'tax_group_name',
            'tax_group_description',
            'description',
            'is_active',
            'source_url',
            'source_type',
            'source_name',
            'parser_name',
            'source_hash',
            'effective_from',
            'effective_to',
            'imported_at',
        ];

        foreach ($columns as $column) {
            if (!Schema::hasColumn('kodzero_posmall_taxes', $column)) {
                continue;
            }

            Schema::table('kodzero_posmall_taxes', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
}
