<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallCustomerGroupPrices extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_customer_group_prices', function ($table) {
            $table->increments('id');
            $table->integer('customer_group_id');
            $table->integer('currency_id');
            $table->integer('priceable_id');
            $table->string('priceable_type');
            $table->integer('price');
            $table->timestamps();

            if (! app()->runningUnitTests()) {
                $table->unique(
                    ['customer_group_id', 'priceable_id', 'priceable_type', 'currency_id'],
                    'kodzero_posmall_customer_group_unique_price'
                );
                $table->index('currency_id', 'idx_kodzero_posmall_group_price_currency');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_customer_group_prices');
    }
}
