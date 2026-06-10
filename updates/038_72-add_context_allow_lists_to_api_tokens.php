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
        if (!Schema::hasTable('kodzero_posmall_api_tokens')) {
            return;
        }

        Schema::table('kodzero_posmall_api_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('kodzero_posmall_api_tokens', 'allowed_customer_ids')) {
                $table->jsonb('allowed_customer_ids')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_api_tokens', 'allowed_vendor_ids')) {
                $table->jsonb('allowed_vendor_ids')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_api_tokens', 'allowed_channel_ids')) {
                $table->jsonb('allowed_channel_ids')->nullable();
            }

            if (!Schema::hasColumn('kodzero_posmall_api_tokens', 'allowed_warehouse_ids')) {
                $table->jsonb('allowed_warehouse_ids')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('kodzero_posmall_api_tokens')) {
            return;
        }

        Schema::table('kodzero_posmall_api_tokens', function (Blueprint $table) {
            foreach (['allowed_warehouse_ids', 'allowed_channel_ids', 'allowed_vendor_ids', 'allowed_customer_ids'] as $column) {
                if (Schema::hasColumn('kodzero_posmall_api_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
