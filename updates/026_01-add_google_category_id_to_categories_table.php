<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddGoogleCategoryIdToCategoriesTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_categories', 'google_product_category_id')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function (Blueprint $table) {
            $table->integer('google_product_category_id')->after('sort_order')->nullable();
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('kodzero_posmall_categories', 'google_product_category_id')) {
            return;
        }

        Schema::table('kodzero_posmall_categories', function (Blueprint $table) {
            $table->dropColumn(['google_product_category_id']);
        });
    }
}
