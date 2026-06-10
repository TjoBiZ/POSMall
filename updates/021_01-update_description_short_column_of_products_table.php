<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class UpdateDescriptionShortColumnOfProductsTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('kodzero_posmall_products', 'description_short')) {
            return;
        }

        Schema::table('kodzero_posmall_products', function ($table) {
            $table->text('description_short')->nullable()->change();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_products', 'description_short')) {
            return;
        }

        Schema::table('kodzero_posmall_products', function ($table) {
            $table->string('description_short', 255)->nullable()->change();
        });
    }
}
