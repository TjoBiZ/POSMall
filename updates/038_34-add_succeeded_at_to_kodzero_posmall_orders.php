<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('kodzero_posmall_orders', 'succeeded_at')) {
            Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
                $table->date('succeeded_at')->nullable();
            });
        }

        DB::table('kodzero_posmall_orders')
            ->whereNotNull('paid_at')
            ->update(['succeeded_at' => now()]);
    }

    public function down()
    {
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            $table->dropColumn('succeeded_at');
        });
    }
};
