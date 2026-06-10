<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddBrandColumnToOrderProductsTable extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_order_products', function (Blueprint $table) {
            $table->text('brand')->after('variant_name')->nullable();
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_order_products', function (Blueprint $table) {
            $table->dropColumn(['brand']);
        });
    }
}
