<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallProductFileVariantTable_030_08 extends Migration
{
    /**
     * Install Migration
     *
     * @return void
     */
    public function up()
    {
        Schema::create('kodzero_posmall_product_file_variant', function (Blueprint $table) {
            $table->integer('product_file_id');
            $table->integer('variant_id');
            $table->primary(['product_file_id', 'variant_id']);
        });
    }

    /**
     * Uninstall Migration
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_product_file_variant');
    }
};
