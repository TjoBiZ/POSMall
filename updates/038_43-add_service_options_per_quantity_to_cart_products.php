<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddServiceOptionsPerQuantityToCartProducts extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_cart_products', 'service_options_per_quantity')) {
            return;
        }

        Schema::table('kodzero_posmall_cart_products', function ($table) {
            $table->boolean('service_options_per_quantity')->default(true);
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_cart_products', 'service_options_per_quantity')) {
            return;
        }

        Schema::table('kodzero_posmall_cart_products', function ($table) {
            $table->dropColumn('service_options_per_quantity');
        });
    }
}
