<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddUserBelongsToGroupDiscount extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_discounts', function ($table) {
            $table->integer('customer_group_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_discounts', function ($table) {
            $table->dropColumn(['customer_group_id']);
        });
    }
}
