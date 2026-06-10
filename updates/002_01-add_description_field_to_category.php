<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddDescriptionFieldToCategory extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_categories', 'description')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function ($table) {
            $table->text('description')->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_categories', 'description')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function ($table) {
            $table->dropColumn(['description']);
        });
    }
}
