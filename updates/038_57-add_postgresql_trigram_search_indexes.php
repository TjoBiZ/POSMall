<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'idx_kodzero_posmall_products_name_trgm' => [
            'table' => 'kodzero_posmall_products',
            'column' => 'name',
            'where' => 'deleted_at IS NULL AND published = true',
        ],
        'idx_kodzero_posmall_products_slug_trgm' => [
            'table' => 'kodzero_posmall_products',
            'column' => 'slug',
            'where' => 'deleted_at IS NULL AND published = true',
        ],
        'idx_kodzero_posmall_products_sku_trgm' => [
            'table' => 'kodzero_posmall_products',
            'column' => 'user_defined_id',
            'where' => 'deleted_at IS NULL AND published = true AND user_defined_id IS NOT NULL',
        ],
        'idx_kodzero_posmall_products_mpn_trgm' => [
            'table' => 'kodzero_posmall_products',
            'column' => 'mpn',
            'where' => 'deleted_at IS NULL AND published = true AND mpn IS NOT NULL',
        ],
        'idx_kodzero_posmall_products_gtin_trgm' => [
            'table' => 'kodzero_posmall_products',
            'column' => 'gtin',
            'where' => 'deleted_at IS NULL AND published = true AND gtin IS NOT NULL',
        ],
        'idx_kodzero_posmall_variants_name_trgm' => [
            'table' => 'kodzero_posmall_product_variants',
            'column' => 'name',
            'where' => 'deleted_at IS NULL AND published = true AND name IS NOT NULL',
        ],
        'idx_kodzero_posmall_variants_mpn_trgm' => [
            'table' => 'kodzero_posmall_product_variants',
            'column' => 'mpn',
            'where' => 'deleted_at IS NULL AND published = true AND mpn IS NOT NULL',
        ],
        'idx_kodzero_posmall_variants_gtin_trgm' => [
            'table' => 'kodzero_posmall_product_variants',
            'column' => 'gtin',
            'where' => 'deleted_at IS NULL AND published = true AND gtin IS NOT NULL',
        ],
        'idx_kodzero_posmall_prop_values_value_trgm' => [
            'table' => 'kodzero_posmall_property_values',
            'column' => 'value',
            'where' => 'value IS NOT NULL',
        ],
        'idx_kodzero_posmall_prop_values_index_trgm' => [
            'table' => 'kodzero_posmall_property_values',
            'column' => 'index_value',
            'where' => 'index_value IS NOT NULL',
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::INDEXES as $name => $definition) {
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING gin (%s gin_trgm_ops) WHERE %s',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($definition['table']),
                $this->quoteIdentifier($definition['column']),
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
};
