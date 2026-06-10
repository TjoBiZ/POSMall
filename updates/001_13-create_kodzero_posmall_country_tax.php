<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCountryTax extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_country_tax', function ($table) {
            $table->increments('id');
            $table->integer('country_id');
            $table->integer('tax_id');

            $table->unique(['country_id', 'tax_id']);
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_country_tax');
    }
}
