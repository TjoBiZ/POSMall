<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallNotifications extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_notifications', function ($table) {
            $table->increments('id');
            $table->boolean('enabled')->default(1);
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('template');
            $table->integer('sort_order')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_notifications');
    }
}
