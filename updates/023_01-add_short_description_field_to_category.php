<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddShortDescriptionFieldToCategory extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_categories', 'description_short')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function ($table) {
            $table->string('description_short', 255)->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_categories', 'description_short')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function ($table) {
            $table->dropColumn(['description_short']);
        });
    }
}
