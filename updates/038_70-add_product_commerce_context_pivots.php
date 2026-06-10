<?php
declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('kodzero_posmall_product_vendor')) {
            Schema::create('kodzero_posmall_product_vendor', function (Blueprint $table) {
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('vendor_id');
                $table->timestamps();
                $table->primary(['product_id', 'vendor_id'], 'pk_kodzero_posmall_product_vendor');
                $table->index('vendor_id');
            });
        }

        if (!Schema::hasTable('kodzero_posmall_product_channel')) {
            Schema::create('kodzero_posmall_product_channel', function (Blueprint $table) {
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('channel_id');
                $table->timestamps();
                $table->primary(['product_id', 'channel_id'], 'pk_kodzero_posmall_product_channel');
                $table->index('channel_id');
            });
        }

        if (!Schema::hasTable('kodzero_posmall_warehouse_inventory')) {
            Schema::create('kodzero_posmall_warehouse_inventory', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('warehouse_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedInteger('variant_id')->nullable()->index();
                $table->integer('stock')->default(0);
                $table->timestamps();
                $table->unique(['warehouse_id', 'product_id', 'variant_id'], 'uniq_kodzero_posmall_warehouse_inventory_item');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kodzero_posmall_warehouse_inventory');
        Schema::dropIfExists('kodzero_posmall_product_channel');
        Schema::dropIfExists('kodzero_posmall_product_vendor');
    }
};
