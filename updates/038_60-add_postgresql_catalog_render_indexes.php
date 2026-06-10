<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'idx_kodzero_posmall_category_product_category_product' => [
            'table' => 'kodzero_posmall_category_product',
            'columns' => ['category_id', 'product_id'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => $definition) {
            $this->dropInvalidIndexIfExists($name);

            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($definition['table']),
                implode(', ', array_map([$this, 'quoteIdentifier'], $definition['columns']))
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement(sprintf(
                'DROP INDEX CONCURRENTLY IF EXISTS %s',
                $this->quoteIdentifier($name)
            ));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function dropInvalidIndexIfExists(string $name): void
    {
        $result = DB::selectOne(
            <<<'SQL'
select exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    join pg_index i on i.indexrelid = c.oid
    where n.nspname = current_schema()
      and c.relname = ?
      and i.indisvalid = false
) as invalid_index_exists
SQL,
            [$name]
        );

        if (! $result || ! $result->invalid_index_exists) {
            return;
        }

        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->quoteIdentifier($name)
        ));
    }
};
