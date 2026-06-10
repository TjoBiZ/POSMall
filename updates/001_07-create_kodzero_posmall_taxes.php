<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallTaxes extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_taxes', function ($table) {
            $table->increments('id');
            $table->string('name', 255);
            $table->decimal('percentage');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_taxes');
    }
}
