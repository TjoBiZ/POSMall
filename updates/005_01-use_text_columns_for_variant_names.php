<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class UseTextColumnsForVariantNames extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_order_products', function ($table) {
            $table->text('variant_name')->nullable()->change();
        });
    }

    public function down()
    {
        // Leave the columns. The migration might fail if data gets truncated.
    }
}
