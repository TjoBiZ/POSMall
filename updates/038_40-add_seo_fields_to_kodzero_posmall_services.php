<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddSeoFieldsToKodZeroPOSMallServices extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_services', function (Blueprint $table) {
            if (! Schema::hasColumn('kodzero_posmall_services', 'meta_title')) {
                $table->string('meta_title')->nullable();
            }

            if (! Schema::hasColumn('kodzero_posmall_services', 'meta_keywords')) {
                $table->text('meta_keywords')->nullable();
            }

            if (! Schema::hasColumn('kodzero_posmall_services', 'meta_description')) {
                $table->text('meta_description')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_services', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('kodzero_posmall_services', 'meta_title') ? 'meta_title' : null,
                Schema::hasColumn('kodzero_posmall_services', 'meta_keywords') ? 'meta_keywords' : null,
                Schema::hasColumn('kodzero_posmall_services', 'meta_description') ? 'meta_description' : null,
            ]);

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
}
