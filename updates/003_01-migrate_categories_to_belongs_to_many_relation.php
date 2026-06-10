<?php

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Updates\Migration;
use Schema;

class MigrateCategoriesToBelongstoManyRelation extends Migration
{
    public function up()
    {
        Schema::create('kodzero_posmall_category_product', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('category_id');
            $table->integer('sort_order')->nullable();
        });

        // Migrate products to new structure. Migrate the category sort order as well.
        $sortOrders = DB::table('kodzero_posmall_category_product_sort_order')->get()->mapWithKeys(fn ($item) => [$item->category_id . '-' . $item->product_id => $item->sort_order]);

        $products = DB::table('kodzero_posmall_products')->get();
        $products->each(function ($product, $index) use ($sortOrders) {
            if ($product->category_id === null) {
                return;
            }

            $orderKey  = $product->category_id . '-' . $product->id;
            $sortOrder = $sortOrders[$orderKey] ?? $index;

            DB::table('kodzero_posmall_category_product')->insert([
                'product_id'  => $product->id,
                'category_id' => $product->category_id,
                'sort_order'  => $sortOrder,
            ]);
        });

        Schema::table('kodzero_posmall_products', function ($table) {
            if (! app()->runningUnitTests()) {
                $table->dropIndex('idx_kodzero_posmall_product_category_id');
            }
            $table->dropColumn(['category_id']);
        });

        Schema::drop('kodzero_posmall_category_product_sort_order');
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_category_product');
        Schema::table('kodzero_posmall_products', function ($table) {
            $table->integer('category_id')->nullable();
        });
    }
}
