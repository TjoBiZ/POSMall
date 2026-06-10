<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddDescriptionColumnsToVariants extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_product_variants', function ($table) {
            $table->string('description_short', 255)->nullable();
            $table->text('description')->nullable();
        });
    }

    public function down()
    {
        Schema::table('kodzero_posmall_product_variants', function ($table) {
            $table->dropColumn(['description_short', 'description']);
        });
    }
}
