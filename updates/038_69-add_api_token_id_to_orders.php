<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('kodzero_posmall_orders', 'api_token_id')) {
                $table->unsignedInteger('api_token_id')->nullable()->index();
            }
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_kodzero_posmall_orders_api_idempotency_scope
ON kodzero_posmall_orders (
    api_token_id,
    customer_id,
    vendor_id,
    channel_id,
    warehouse_id,
    api_idempotency_key
)
WHERE api_source = 'api'
  AND api_token_id IS NOT NULL
  AND api_idempotency_key IS NOT NULL
SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_kodzero_posmall_orders_api_idempotency_scope');
        }

        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            if (Schema::hasColumn('kodzero_posmall_orders', 'api_token_id')) {
                $table->dropColumn('api_token_id');
            }
        });
    }
};
