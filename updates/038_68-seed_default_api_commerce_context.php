<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            'kodzero_posmall_vendors' => ['name' => 'Default Vendor', 'slug' => 'default-vendor'],
            'kodzero_posmall_channels' => ['name' => 'Default Storefront', 'slug' => 'default-storefront', 'type' => 'storefront'],
            'kodzero_posmall_warehouses' => ['name' => 'Default Warehouse', 'slug' => 'default-warehouse', 'type' => 'default'],
        ] as $table => $row) {
            if (DB::table($table)->where('slug', $row['slug'])->exists()) {
                continue;
            }

            DB::table($table)->insert($row + [
                'is_active' => true,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
    }
};
