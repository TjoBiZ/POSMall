<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddGalleryAutoplaySecondsToKodZeroPOSMallServices extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_services', 'gallery_autoplay_seconds')) {
            return;
        }

        Schema::table('kodzero_posmall_services', function ($table) {
            $table->decimal('gallery_autoplay_seconds', 5, 2)->nullable()->default(5.00);
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_services', 'gallery_autoplay_seconds')) {
            return;
        }

        Schema::table('kodzero_posmall_services', function ($table) {
            $table->dropColumn('gallery_autoplay_seconds');
        });
    }
}
