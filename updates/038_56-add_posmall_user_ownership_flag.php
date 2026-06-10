<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class AddPosmallUserOwnershipFlag extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('users', 'kodzero_posmall_owned_user')) {
            return;
        }

        Schema::table('users', function ($table) {
            $table->boolean('kodzero_posmall_owned_user')->default(false);
        });
    }

    public function down()
    {
        if (! Schema::hasColumn('users', 'kodzero_posmall_owned_user')) {
            return;
        }

        Schema::table('users', function ($table) {
            $table->dropColumn('kodzero_posmall_owned_user');
        });
    }
}
