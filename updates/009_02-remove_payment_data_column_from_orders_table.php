<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class RemovePaymentDataColumnFromOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_orders', function ($table) {
            if (Schema::hasColumn('kodzero_posmall_orders', 'payment_data')) {
                $table->dropColumn(['payment_data']);
            }
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_orders', function ($table) {
            $table->text('payment_data')->nullable();
        });
    }
}
