<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX_NAME = 'idx_kodzero_posmall_products_service_carriers';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            <<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS %s
ON %s (id)
WHERE deleted_at IS NULL
  AND published = true
  AND user_defined_id::text LIKE 'POSMALL-SERVICE-CARRIER-%%'
SQL,
            $this->quoteIdentifier(self::INDEX_NAME),
            $this->quoteIdentifier('kodzero_posmall_products')
        ));
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->quoteIdentifier(self::INDEX_NAME)
        ));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
