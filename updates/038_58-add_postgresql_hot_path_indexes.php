<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'idx_kodzero_posmall_cart_products_lookup' => [
            'table' => 'kodzero_posmall_cart_products',
            'columns' => ['cart_id', 'product_id', 'variant_id'],
        ],
        'idx_kodzero_posmall_addresses_customer_active' => [
            'table' => 'kodzero_posmall_addresses',
            'columns' => ['customer_id', 'deleted_at', 'id'],
            'where' => 'customer_id IS NOT NULL',
        ],
        'idx_kodzero_posmall_orders_customer_created' => [
            'table' => 'kodzero_posmall_orders',
            'columns' => ['customer_id', 'created_at DESC', 'id DESC'],
            'where' => 'customer_id IS NOT NULL',
        ],
        'idx_kodzero_posmall_orders_session_payment' => [
            'table' => 'kodzero_posmall_orders',
            'columns' => ['session_id', 'payment_state', 'id DESC'],
            'where' => 'session_id IS NOT NULL',
        ],
        'idx_kodzero_posmall_orders_ip_created' => [
            'table' => 'kodzero_posmall_orders',
            'columns' => ['ip_address', 'created_at DESC', 'id DESC'],
            'where' => 'ip_address IS NOT NULL',
        ],
        'idx_kodzero_posmall_payments_log_order_created' => [
            'table' => 'kodzero_posmall_payments_log',
            'columns' => ['order_id', 'created_at DESC', 'id DESC'],
            'where' => 'order_id IS NOT NULL',
        ],
        'idx_kodzero_posmall_tax_region_zip_lookup' => [
            'table' => 'kodzero_posmall_usa_tax_region_rows',
            'columns' => ['state_code', 'tax_group_code', 'zip_from', 'zip_to', 'total_rate_percent DESC', 'id'],
            'where' => 'tax_id IS NOT NULL AND zip4_from IS NULL AND zip4_to IS NULL',
        ],
        'idx_kodzero_posmall_tax_region_zip4_lookup' => [
            'table' => 'kodzero_posmall_usa_tax_region_rows',
            'columns' => ['state_code', 'tax_group_code', 'zip_from', 'zip_to', 'zip4_from', 'zip4_to', 'total_rate_percent DESC', 'id'],
            'where' => 'tax_id IS NOT NULL AND zip4_from IS NOT NULL AND zip4_to IS NOT NULL',
        ],
        'idx_kodzero_posmall_tax_staging_filter' => [
            'table' => 'kodzero_posmall_usa_tax_import_staging',
            'columns' => ['status', 'state_code', 'tax_group_code', 'created_at', 'id'],
        ],
        'idx_kodzero_posmall_tax_staging_dedupe' => [
            'table' => 'kodzero_posmall_usa_tax_import_staging',
            'columns' => ['state_code', 'tax_group_code', 'jurisdiction_code', 'source_hash', 'status'],
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $name => $definition) {
            DB::statement(sprintf(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)%s',
                $this->quoteIdentifier($name),
                $this->quoteIdentifier($definition['table']),
                implode(', ', array_map([$this, 'quoteIndexColumn'], $definition['columns'])),
                isset($definition['where']) ? ' WHERE ' . $definition['where'] : ''
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

        return $this->quoteIdentifier($column);
    }
};
