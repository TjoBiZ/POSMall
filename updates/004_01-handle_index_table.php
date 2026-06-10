<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class HandleIndexTable extends Migration
{
    public function up()
    {
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_index');
    }
}
