<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddNameColumnToIndexTable extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('kodzero_posmall_index')) {
            return;
        }
        Schema::table('kodzero_posmall_index', function (Blueprint $table) {
            if (! Schema::hasColumn('kodzero_posmall_index', 'name')) {
                $table->string('name', 191);
            }
        });
    }

    public function down()
    {
        // Do nothing.
    }
}
