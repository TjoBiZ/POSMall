<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProperties extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_properties', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->string('type');
            $table->string('unit')->nullable();
            $table->text('options')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            if (! app()->runningUnitTests()) {
                $table->index('deleted_at', 'idx_kodzero_posmall_property_deleted_at');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_properties');
    }
}
