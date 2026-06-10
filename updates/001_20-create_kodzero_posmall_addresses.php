<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallAddresses extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_addresses', function ($table) {
            $table->increments('id');
            $table->string('company')->nullable();
            $table->string('name')->nullable();
            $table->text('lines');
            $table->string('zip', 20);
            $table->string('city');
            $table->integer('state_id')->nullable()->index();
            $table->integer('country_id')->nullable()->index();
            $table->text('details')->nullable();
            $table->integer('customer_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_addresses');
    }
}
