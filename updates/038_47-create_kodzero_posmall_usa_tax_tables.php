<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallUsaTaxTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('kodzero_posmall_category_tax')) {
            Schema::create('kodzero_posmall_category_tax', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('category_id');
                $table->integer('tax_id');
                $table->unique(['category_id', 'tax_id']);
                $table->index(['category_id', 'tax_id']);
            });
        }

        if (!Schema::hasTable('kodzero_posmall_usa_tax_histories')) {
            Schema::create('kodzero_posmall_usa_tax_histories', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('tax_id')->nullable()->index();
                $table->decimal('old_rate_percent', 8, 4)->nullable();
                $table->decimal('new_rate_percent', 8, 4)->nullable();
                $table->string('state_code', 2)->nullable()->index();
                $table->string('tax_group_code', 80)->nullable()->index();
                $table->text('source_url')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->timestamp('changed_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('kodzero_posmall_usa_tax_import_staging')) {
            Schema::create('kodzero_posmall_usa_tax_import_staging', function (Blueprint $table) {
                $table->increments('id');
                $table->string('batch_id', 64)->index();
                $table->string('state_code', 2)->nullable()->index();
                $table->text('source_url')->nullable();
                $table->string('source_type', 30)->nullable();
                $table->string('source_name')->nullable();
                $table->string('parser_name')->nullable();
                $table->string('raw_name')->nullable();
                $table->string('parsed_name')->nullable();
                $table->string('tax_group_code', 80)->nullable()->index();
                $table->string('tax_group_name')->nullable();
                $table->text('tax_group_description')->nullable();
                $table->decimal('rate_percent', 8, 4)->nullable();
                $table->text('description')->nullable();
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->string('source_hash', 64)->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }

        $this->addSellRestrictionColumn('kodzero_posmall_products');
        $this->addSellRestrictionColumn('kodzero_posmall_categories');
        $this->addSellRestrictionColumn('kodzero_posmall_services');
    }

    public function down()
    {
        $this->dropSellRestrictionColumn('kodzero_posmall_services');
        $this->dropSellRestrictionColumn('kodzero_posmall_categories');
        $this->dropSellRestrictionColumn('kodzero_posmall_products');

        Schema::dropIfExists('kodzero_posmall_usa_tax_import_staging');
        Schema::dropIfExists('kodzero_posmall_usa_tax_histories');
        Schema::dropIfExists('kodzero_posmall_category_tax');
    }

    protected function addSellRestrictionColumn(string $tableName): void
    {
        if (Schema::hasColumn($tableName, 'sell_only_to_tax_states')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->boolean('sell_only_to_tax_states')->default(false)->index();
        });
    }

    protected function dropSellRestrictionColumn(string $tableName): void
    {
        if (!Schema::hasColumn($tableName, 'sell_only_to_tax_states')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('sell_only_to_tax_states');
        });
    }
}
