<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddIpAddressToKodZeroPOSMallReviews extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_reviews', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_reviews', 'ip_address')) {
                $table->string('ip_address', 45)->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_reviews', function ($table) {
            if (Schema::hasColumn('kodzero_posmall_reviews', 'ip_address')) {
                $table->dropColumn('ip_address');
            }
        });
    }
}
