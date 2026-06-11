<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'kodzero_posmall_index';

    private const INDEXES = [
        'idx_product_variant_index_is_ghost' => [
            'target' => 'idx_kodzero_posmall_product_variant_index_is_ghost',
            'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s, %s, %s, %s)',
            'columns' => ['product_id', 'variant_id', 'index', 'is_ghost'],
        ],
        'idx_published_index' => [
            'target' => 'idx_kodzero_posmall_published_index',
            'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s, %s)',
            'columns' => ['index', 'published'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || !Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (self::INDEXES as $legacyName => $definition) {
            $this->renameOrDropLegacyIndex($legacyName, $definition['target']);
            $this->createIndex($definition['target'], $definition['sql'], $definition['columns']);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $definition) {
            DB::statement(sprintf(
                'DROP INDEX CONCURRENTLY IF EXISTS %s',
                $this->quoteIdentifier($definition['target'])
            ));
        }
    }

    private function renameOrDropLegacyIndex(string $legacyName, string $targetName): void
    {
        if ($this->indexTable($legacyName) !== self::TABLE) {
            return;
        }

        if (!$this->indexExists($targetName)) {
            DB::statement(sprintf(
                'ALTER INDEX %s RENAME TO %s',
                $this->quoteIdentifier($legacyName),
                $this->quoteIdentifier($targetName)
            ));

            return;
        }

        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->quoteIdentifier($legacyName)
        ));
    }

    private function createIndex(string $name, string $sql, array $columns): void
    {
        DB::statement(sprintf(
            $sql,
            $this->quoteIdentifier($name),
            $this->quoteIdentifier(self::TABLE),
            ...array_map([$this, 'quoteIdentifier'], $columns)
        ));
    }

    private function indexExists(string $name): bool
    {
        $result = DB::selectOne(
            <<<'SQL'
select exists (
    select 1
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = current_schema()
      and c.relname = ?
) as index_exists
SQL,
            [$name]
        );

        return (bool)($result->index_exists ?? false);
    }

    private function indexTable(string $name): ?string
    {
        $result = DB::selectOne(
            <<<'SQL'
select t.relname as table_name
from pg_class i
join pg_namespace n on n.oid = i.relnamespace
join pg_index ix on ix.indexrelid = i.oid
join pg_class t on t.oid = ix.indrelid
where n.nspname = current_schema()
  and i.relname = ?
limit 1
SQL,
            [$name]
        );

        return $result->table_name ?? null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
