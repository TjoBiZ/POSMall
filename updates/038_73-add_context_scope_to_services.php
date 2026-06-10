<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContextScopeToServices extends \October\Rain\Database\Updates\Migration
{
    public function up(): void
    {
        Schema::table('kodzero_posmall_services', function (Blueprint $table): void {
            if (!Schema::hasColumn('kodzero_posmall_services', 'vendor_id')) {
                $table->unsignedInteger('vendor_id')->nullable()->index();
            }

            if (!Schema::hasColumn('kodzero_posmall_services', 'channel_id')) {
                $table->unsignedInteger('channel_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('kodzero_posmall_services', function (Blueprint $table): void {
            foreach (['channel_id', 'vendor_id'] as $column) {
                if (Schema::hasColumn('kodzero_posmall_services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
