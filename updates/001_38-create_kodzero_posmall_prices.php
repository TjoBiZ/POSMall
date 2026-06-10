<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallPrices extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_prices', function ($table) {
            $table->increments('id');
            $table->integer('currency_id');
            $table->integer('priceable_id');
            $table->string('priceable_type');
            $table->integer('price')->nullable();
            $table->integer('price_category_id')->nullable();
            $table->string('field')->nullable();
            $table->timestamps();

            if (! app()->runningUnitTests()) {
                $table->unique(
                    ['price_category_id', 'priceable_id', 'priceable_type', 'currency_id', 'field'],
                    'kodzero_posmall_unique_price'
                );
                $table->index(['priceable_id', 'priceable_type'], 'idx_kodzero_posmall_price_priceable');
                $table->index('currency_id', 'idx_kodzero_posmall_price_currency');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_prices');
    }
}
