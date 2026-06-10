<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use RuntimeException;
use Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'kodzero_posmall_index';

    private const JSON_COLUMNS = [
        'category_id',
        'property_values',
        'sort_orders',
        'prices',
        'parent_prices',
        'customer_group_prices',
    ];

    private const CONTAINMENT_INDEXES = [
        'idx_kodzero_posmall_index_category_id_gin_published' => 'category_id',
        'idx_kodzero_posmall_index_property_values_gin_published' => 'property_values',
    ];

    public function up(): void
    {
        if (!$this->isPostgreSQL() || !Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->convertColumnsTo('jsonb');
        $this->dropInvalidContainmentIndexes();
        $this->createContainmentIndexes();
    }

    public function down(): void
    {
        if (!$this->isPostgreSQL() || !Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->dropContainmentIndexes();
        $this->convertColumnsTo('json');
    }

    private function isPostgreSQL(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function convertColumnsTo(string $targetType): void
    {
        $columns = $this->columnsRequiringConversion($targetType);

        if ($columns === []) {
            return;
        }

        $clauses = array_map(function (string $column) use ($targetType): string {
            $quotedColumn = $this->quoteIdentifier($column);

            return sprintf(
                'ALTER COLUMN %1$s TYPE %2$s USING %1$s::%2$s',
                $quotedColumn,
                $targetType
            );
        }, $columns);

        DB::statement(sprintf(
            'ALTER TABLE %s %s',
            $this->quoteIdentifier(self::TABLE),
            implode(', ', $clauses)
        ));
    }

    private function columnsRequiringConversion(string $targetType): array
    {
        $expectedSourceType = $targetType === 'jsonb' ? 'json' : 'jsonb';
        $columnTypes = DB::table('information_schema.columns')
            ->whereRaw('table_schema = current_schema()')
            ->where('table_name', self::TABLE)
            ->whereIn('column_name', self::JSON_COLUMNS)
            ->pluck('data_type', 'column_name')
            ->all();

        $columns = [];

        foreach (self::JSON_COLUMNS as $column) {
            if (!array_key_exists($column, $columnTypes)) {
                continue;
            }

            if ($columnTypes[$column] === $targetType) {
                continue;
            }

            if ($columnTypes[$column] !== $expectedSourceType) {
                throw new RuntimeException(sprintf(
                    'Unexpected %s.%s column type [%s], expected [%s] or [%s].',
                    self::TABLE,
                    $column,
                    $columnTypes[$column],
                    $targetType,
                    $expectedSourceType
                ));
            }

            $columns[] = $column;
        }

        return $columns;
    }

    private function dropInvalidContainmentIndexes(): void
    {
        foreach (array_keys(self::CONTAINMENT_INDEXES) as $indexName) {
            if ($this->isValidIndex($indexName) !== false) {
                continue;
            }

            $this->dropIndexConcurrently($indexName);
        }
    }

    private function isValidIndex(string $indexName): ?bool
    {
        $row = DB::selectOne(
            'select i.indisvalid as valid
             from pg_index i
             join pg_class c on c.oid = i.indexrelid
             join pg_namespace n on n.oid = c.relnamespace
             where n.nspname = current_schema()
               and c.relname = ?',
            [$indexName]
        );

        return $row === null ? null : (bool)$row->valid;
    }

    private function createContainmentIndexes(): void
    {
        foreach (self::CONTAINMENT_INDEXES as $indexName => $column) {
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin (%s jsonb_path_ops) WHERE %s = true',
                $this->quoteIdentifier($indexName),
                $this->quoteIdentifier(self::TABLE),
                $this->quoteIdentifier($column),
                $this->quoteIdentifier('published')
            ));
        }
    }

    private function dropContainmentIndexes(): void
    {
        foreach (array_keys(self::CONTAINMENT_INDEXES) as $indexName) {
            $this->dropIndexConcurrently($indexName);
        }
    }

    private function dropIndexConcurrently(string $indexName): void
    {
        DB::statement(sprintf(
            'DROP INDEX CONCURRENTLY IF EXISTS %s',
            $this->quoteIdentifier($indexName)
        ));
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
