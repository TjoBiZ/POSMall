<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'idx_kodzero_posmall_index_catalog_bestseller' => [
            'table' => 'kodzero_posmall_index',
            'columns' => ['index', 'sales_count DESC', 'product_id ASC'],
            'include' => ['variant_id', 'is_ghost'],
            'where' => 'published = true',
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => $definition) {
            if (!Schema::hasTable($definition['table'])) {
                continue;
            }

            $this->dropInvalidIndexIfExists($name);

            $include = empty($definition['include'])
                ? ''
                : ' INCLUDE (' . implode(', ', array_map([$this, 'quoteIdentifier'], $definition['include'])) . ')';

            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s WHERE %s',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($definition['table']),
                implode(', ', array_map([$this, 'quoteIndexColumn'], $definition['columns'])),
                $include,
                $definition['where']
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

    private function quoteIndexColumn(string $column): string
    {
        if (str_ends_with($column, ' DESC')) {
            return $this->quoteIdentifier(substr($column, 0, -5)) . ' DESC';
        }

        if (str_ends_with($column, ' ASC')) {
            return $this->quoteIdentifier(substr($column, 0, -4)) . ' ASC';
        }

        return $this->quoteIdentifier($column);
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
