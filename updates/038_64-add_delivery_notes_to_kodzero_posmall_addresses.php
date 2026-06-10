<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddDeliveryNotesToKodZeroPOSMallAddresses extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_addresses', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_addresses', 'delivery_notes')) {
                $table->text('delivery_notes')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_addresses', function ($table) {
            if (Schema::hasColumn('kodzero_posmall_addresses', 'delivery_notes')) {
                $table->dropColumn('delivery_notes');
            }
        });
    }
}
