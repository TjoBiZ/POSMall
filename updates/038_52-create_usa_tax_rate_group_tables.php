<?php

declare(strict_types=1);

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kodzero_posmall_usa_tax_rate_groups')) {
            Schema::create('kodzero_posmall_usa_tax_rate_groups', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('state_code', 2)->index();
                $table->string('tax_group_code', 80)->nullable()->index();
                $table->string('tax_group_name')->nullable();
                $table->text('tax_group_description')->nullable();
                $table->decimal('rate_percent', 8, 4)->default(0);
                $table->decimal('state_rate_percent', 8, 4)->nullable();
                $table->decimal('local_rate_percent', 8, 4)->nullable();
                $table->string('taxability_mode', 60)->nullable();
                $table->text('region_names')->nullable();
                $table->text('county_names')->nullable();
                $table->text('city_names')->nullable();
                $table->text('zip_codes')->nullable();
                $table->text('zip_ranges')->nullable();
                $table->text('description')->nullable();
                $table->text('info')->nullable();
                $table->text('source_url')->nullable();
                $table->string('source_type', 30)->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->integer('source_rows_count')->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('kodzero_posmall_usa_tax_region_rows')) {
            Schema::create('kodzero_posmall_usa_tax_region_rows', function (Blueprint $table) {
                $table->increments('id');
                $table->string('batch_id', 64)->nullable()->index();
                $table->integer('group_id')->nullable()->index();
                $table->string('state_code', 2)->nullable()->index();
                $table->string('county_name')->nullable();
                $table->string('city_name')->nullable();
                $table->string('region_name')->nullable();
                $table->string('jurisdiction_name')->nullable();
                $table->string('jurisdiction_code', 120)->nullable();
                $table->string('zip_code', 20)->nullable();
                $table->string('zip_from', 20)->nullable();
                $table->string('zip_to', 20)->nullable();
                $table->string('zip4_from', 20)->nullable();
                $table->string('zip4_to', 20)->nullable();
                $table->decimal('state_rate_percent', 8, 4)->nullable();
                $table->decimal('county_rate_percent', 8, 4)->nullable();
                $table->decimal('city_rate_percent', 8, 4)->nullable();
                $table->decimal('district_rate_percent', 8, 4)->nullable();
                $table->decimal('local_rate_percent', 8, 4)->nullable();
                $table->decimal('total_rate_percent', 8, 4)->nullable()->index();
                $table->string('tax_group_code', 80)->nullable()->index();
                $table->string('taxability_mode', 60)->nullable();
                $table->text('source_url')->nullable();
                $table->string('source_type', 30)->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->text('raw_payload')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->timestamps();
            });
        }

        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                foreach ($this->groupColumns() as $name => $definition) {
                    if (!Schema::hasColumn($table->getTable(), $name)) {
                        $definition($table);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['kodzero_posmall_taxes', 'kodzero_posmall_usa_tax_import_staging'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                foreach (array_keys($this->groupColumns()) as $name) {
                    if (Schema::hasColumn($table->getTable(), $name)) {
                        $table->dropColumn($name);
                    }
                }
            });
        }

        Schema::dropIfExists('kodzero_posmall_usa_tax_region_rows');
        Schema::dropIfExists('kodzero_posmall_usa_tax_rate_groups');
    }

    protected function groupColumns(): array
    {
        return [
            'taxability_mode' => fn (Blueprint $table) => $table->string('taxability_mode', 60)->nullable(),
            'region_names' => fn (Blueprint $table) => $table->text('region_names')->nullable(),
            'county_names' => fn (Blueprint $table) => $table->text('county_names')->nullable(),
            'city_names' => fn (Blueprint $table) => $table->text('city_names')->nullable(),
            'zip_codes' => fn (Blueprint $table) => $table->text('zip_codes')->nullable(),
            'zip_ranges' => fn (Blueprint $table) => $table->text('zip_ranges')->nullable(),
            'info' => fn (Blueprint $table) => $table->text('info')->nullable(),
            'source_rows_count' => fn (Blueprint $table) => $table->integer('source_rows_count')->default(0),
        ];
    }
};
