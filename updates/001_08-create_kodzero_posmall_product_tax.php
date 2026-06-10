<?php

namespace KodZero\POSMall\Updates;

use Illuminate\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductTax extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_product_tax', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('tax_id');
            $table->integer('product_id');

            $table->unique(['tax_id', 'product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_product_tax');
    }
}
