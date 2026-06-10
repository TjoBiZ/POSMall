<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('kodzero_posmall_orders', 'api_source')) {
                $table->string('api_source', 32)->default('web')->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'api_idempotency_key')) {
                $table->string('api_idempotency_key', 191)->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'payment_link_token_hash')) {
                $table->string('payment_link_token_hash', 64)->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'payment_link_expires_at')) {
                $table->timestamp('payment_link_expires_at')->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'payment_link_used_at')) {
                $table->timestamp('payment_link_used_at')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'vendor_id')) {
                $table->unsignedInteger('vendor_id')->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'channel_id')) {
                $table->unsignedInteger('channel_id')->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_orders', 'warehouse_id')) {
                $table->unsignedInteger('warehouse_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            foreach ([
                'warehouse_id',
                'channel_id',
                'vendor_id',
                'payment_link_used_at',
                'payment_link_expires_at',
                'payment_link_token_hash',
                'api_idempotency_key',
                'api_source',
            ] as $column) {
                if (Schema::hasColumn('kodzero_posmall_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
