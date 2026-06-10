<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const CATEGORY_TABLE = 'kodzero_posmall_index_categories';
    private const STATS_TABLE = 'kodzero_posmall_index_category_stats';
    private const BRANDS_TABLE = 'kodzero_posmall_index_category_brands';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->createTables();
        $this->createIndexes();
        $this->refreshSummaries();
        $this->analyze();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::dropIfExists(self::BRANDS_TABLE);
        Schema::dropIfExists(self::STATS_TABLE);
    }

    private function createTables(): void
    {
        if (!Schema::hasTable(self::STATS_TABLE)) {
            Schema::create(self::STATS_TABLE, function ($table): void {
                $table->bigIncrements('id');
                $table->string('index_name', 32);
                $table->unsignedInteger('category_id');
                $table->unsignedInteger('total_count')->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable(self::BRANDS_TABLE)) {
            Schema::create(self::BRANDS_TABLE, function ($table): void {
                $table->bigIncrements('id');
                $table->string('index_name', 32);
                $table->unsignedInteger('category_id');
                $table->string('brand', 191);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    private function createIndexes(): void
    {
        $indexes = [
            'idx_kodzero_posmall_index_category_stats_unique' => sprintf(
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_stats_unique'),
                $this->quoteIdentifier(self::STATS_TABLE)
            ),
            'idx_kodzero_posmall_index_category_brands_unique' => sprintf(
                'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (index_name, category_id, brand)',
                $this->quoteIdentifier('idx_kodzero_posmall_index_category_brands_unique'),
                $this->quoteIdentifier(self::BRANDS_TABLE)
            ),
        ];

        foreach ($indexes as $sql) {
            DB::statement($sql);
        }
    }

    private function refreshSummaries(): void
    {
        if (!Schema::hasTable(self::CATEGORY_TABLE)) {
            return;
        }

        DB::statement('TRUNCATE TABLE ' . $this->quoteIdentifier(self::BRANDS_TABLE));
        DB::statement('TRUNCATE TABLE ' . $this->quoteIdentifier(self::STATS_TABLE));

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, total_count, updated_at)
select index_name, category_id, count(*)::integer, now()
from %2$s
group by index_name, category_id
SQL,
            $this->quoteIdentifier(self::STATS_TABLE),
            $this->quoteIdentifier(self::CATEGORY_TABLE)
        ));

        DB::statement(sprintf(
            <<<'SQL'
insert into %1$s (index_name, category_id, brand, updated_at)
select distinct index_name, category_id, brand, now()
from %2$s
where brand <> ''
SQL,
            $this->quoteIdentifier(self::BRANDS_TABLE),
            $this->quoteIdentifier(self::CATEGORY_TABLE)
        ));
    }

    private function analyze(): void
    {
        foreach ([self::STATS_TABLE, self::BRANDS_TABLE] as $table) {
            DB::statement('ANALYZE ' . $this->quoteIdentifier($table));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
