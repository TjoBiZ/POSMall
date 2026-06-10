<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class AddStateCodesToKodZeroPOSMallTaxes extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('kodzero_posmall_taxes', 'state_codes')) {
            return;
        }

        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->text('state_codes')->nullable();
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('kodzero_posmall_taxes', 'state_codes')) {
            return;
        }

        Schema::table('kodzero_posmall_taxes', function (Blueprint $table) {
            $table->dropColumn('state_codes');
        });
    }
}
