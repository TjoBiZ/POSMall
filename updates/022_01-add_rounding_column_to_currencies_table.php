<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddRoundingColumnToCurrenciesTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_currencies', 'rounding')) {
            return;
        }

        Schema::table('kodzero_posmall_currencies', function ($table) {
            $table->integer('rounding')->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_currencies', 'rounding')) {
            return;
        }

        Schema::table('kodzero_posmall_currencies', function ($table) {
            $table->dropColumn('rounding');
        });
    }
}
