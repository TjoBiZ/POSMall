<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class RemoveDescriptionColumnFromOrderProducts extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_order_products', function ($table) {
            if (Schema::hasColumn('kodzero_posmall_order_products', 'description')) {
                $table->dropColumn(['description']);
            }
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_order_products', function ($table) {
            $table->text('description')->nullable();
        });
    }
}
