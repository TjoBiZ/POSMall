<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallWishlistItems extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_wishlist_items', function ($table) {
            $table->increments('id');
            $table->integer('wishlist_id')->index();
            $table->integer('product_id')->index();
            $table->integer('variant_id')->nullable()->index();
            $table->integer('quantity')->default(1);

            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_wishlist_items');
    }
}
