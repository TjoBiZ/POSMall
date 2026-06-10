<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddPaymentMethodIdToDiscountsTable extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_discounts', function ($table) {
            $table->integer('payment_method_id')->after('product_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_discounts', function ($table) {
            $table->dropColumn(['payment_method_id']);
        });
    }
}
