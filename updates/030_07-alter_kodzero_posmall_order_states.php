<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AlterKodZeroPOSMallOrderStates_030_07 extends Migration
{
    /**
     * Install Migration
     *
     * @return void
     */
    public function up()
    {
        Schema::table('kodzero_posmall_order_states', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('sort_order');
        });
    }

    /**
     * Uninstall Migration
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('kodzero_posmall_order_states', 'is_enabled')) {
            if (method_exists(Schema::class, 'dropColumns')) {
                Schema::dropColumns('kodzero_posmall_order_states', 'is_enabled');
            } else {
                Schema::table('kodzero_posmall_order_states', function (Blueprint $table) {
                    $table->dropColumn('is_enabled');
                });
            }
        }
    }
};
