<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallStateTax extends Migration
{
    public function up()
    {
        if (Schema::hasTable('kodzero_posmall_state_tax')) {
            return;
        }

        Schema::create('kodzero_posmall_state_tax', function ($table) {
            $table->increments('id');
            $table->integer('state_id');
            $table->integer('tax_id');

            $table->unique(['state_id', 'tax_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_state_tax');
    }
}
