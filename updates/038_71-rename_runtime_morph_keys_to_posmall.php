<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    private const MAP = [
        'mall.product' => 'posmall.product',
        'mall.variant' => 'posmall.variant',
        'mall.discount' => 'posmall.discount',
        'mall.custom_field' => 'posmall.custom_field',
        'mall.custom_field_option' => 'posmall.custom_field_option',
        'mall.service_option' => 'posmall.service_option',
        'mall.shipping_method' => 'posmall.shipping_method',
        'mall.shipping_method_rate' => 'posmall.shipping_method_rate',
        'mall.payment_method' => 'posmall.payment_method',
        'mall.imageset' => 'posmall.imageset',
    ];

    public function up(): void
    {
        $this->replaceValues('kodzero_posmall_prices', 'priceable_type', self::MAP);

        if (Schema::hasTable('system_files')) {
            $this->replaceValues('system_files', 'attachment_type', self::MAP);
        }
    }

    public function down(): void
    {
        $reverse = array_flip(self::MAP);
        $this->replaceValues('kodzero_posmall_prices', 'priceable_type', $reverse);

        if (Schema::hasTable('system_files')) {
            $this->replaceValues('system_files', 'attachment_type', $reverse);
        }
    }

    private function replaceValues(string $table, string $column, array $map): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        foreach ($map as $from => $to) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }
    }
};
