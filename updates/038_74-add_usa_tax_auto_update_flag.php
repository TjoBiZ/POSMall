<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddUsaTaxAutoUpdateFlag extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_taxes', 'usa_auto_update_enabled')) {
            return;
        }

        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->boolean('usa_auto_update_enabled')->default(false)->index();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('kodzero_posmall_taxes', 'usa_auto_update_enabled')) {
            return;
        }

        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->dropColumn('usa_auto_update_enabled');
        });
    }
}
