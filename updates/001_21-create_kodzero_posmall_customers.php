<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCustomers extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_customers', function ($table) {
            $table->increments('id');
            $table->string('firstname');
            $table->string('lastname');
            $table->boolean('is_guest')->default(0);
            $table->integer('user_id')->nullable();
            $table->integer('default_shipping_address_id')->nullable();
            $table->integer('default_billing_address_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_customers');
    }
}
