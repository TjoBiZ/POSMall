<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddEmbedsColumnToProductsTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_products', 'embeds')) {
            return;
        }

        Schema::table('kodzero_posmall_products', function ($table) {
            $table->text('embeds')->nullable();
        });
    }
    
    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_products', 'embeds')) {
            return;
        }

        Schema::table('kodzero_posmall_products', function ($table) {
            $table->dropColumn('embeds');
        });
    }
}
