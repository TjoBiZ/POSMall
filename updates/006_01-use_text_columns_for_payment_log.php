<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class UseTextColumnsForPaymentLog extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_payments_log', function ($table) {
            $table->text('payment_method')->nullable()->change();
            $table->text('message')->nullable()->change();
        });
    }

    public function down()
    {
        // Leave the columns. The migration might fail if data gets truncated.
    }
}
