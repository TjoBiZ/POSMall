<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use KodZero\POSMall\Models\Notification;
use Schema;

class AddVirtualProductsSupport extends Migration
{
    public function up()
    {
        Schema::table('kodzero_posmall_products', function (Blueprint $table) {
            if (! Schema::hasColumn('kodzero_posmall_products', 'is_virtual')) {
                $table->boolean('is_virtual')->default(false);
            }
            if (! Schema::hasColumn('kodzero_posmall_products', 'file_expires_after_days')) {
                $table->integer('file_expires_after_days')->nullable();
            }
            if (! Schema::hasColumn('kodzero_posmall_products', 'file_max_download_count')) {
                $table->integer('file_max_download_count')->nullable();
            }
            if (! Schema::hasColumn('kodzero_posmall_products', 'file_session_required')) {
                $table->boolean('file_session_required')->default(0);
            }
        });
        if (! Schema::hasTable('kodzero_posmall_product_files')) {
            Schema::create('kodzero_posmall_product_files', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('product_id');
                $table->string('version');
                $table->string('display_name');
                $table->integer('download_count')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('kodzero_posmall_product_file_grants')) {
            Schema::create('kodzero_posmall_product_file_grants', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('order_product_id');
                $table->integer('download_count')->default(0);
                $table->integer('max_download_count')->nullable();
                $table->string('download_key', 64);
                $table->string('display_name')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('expires_at')->nullable();
            });
        }
        Schema::table('kodzero_posmall_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('kodzero_posmall_orders', 'is_virtual')) {
                $table->boolean('is_virtual')->default(0)->after('total_post_taxes');
            }
            if (! Schema::hasColumn('kodzero_posmall_orders', 'paid_at')) {
                $table->date('paid_at')->nullable()->after('shipped_at');
            }
        });
        Schema::table('kodzero_posmall_order_products', function (Blueprint $table) {
            if (! Schema::hasColumn('kodzero_posmall_order_products', 'is_virtual')) {
                $table->boolean('is_virtual')->default(0)->after('quantity');
            }
        });
        Notification::create([
            'enabled'     => true,
            'code'        => 'kodzero.posmall::product.file_download',
            'name'        => 'Virutal product download links',
            'description' => 'Sent when a customer paid for an order with virtual products',
            'template'    => 'kodzero.posmall::mail.product.file_download',
        ]);
    }

    public function down()
    {
        $this->dropColumnsIfExist('kodzero_posmall_products', [
            'is_virtual',
            'file_expires_after_days',
            'file_max_download_count',
            'file_session_required',
        ]);
        $this->dropColumnsIfExist('kodzero_posmall_orders', ['is_virtual', 'paid_at']);
        $this->dropColumnsIfExist('kodzero_posmall_order_products', ['is_virtual']);
        Schema::dropIfExists('kodzero_posmall_product_files');
        Schema::dropIfExists('kodzero_posmall_product_file_grants');
    }

    protected function dropColumnsIfExist(string $tableName, array $columns): void
    {
        $existing = array_filter($columns, fn ($column) => Schema::hasColumn($tableName, $column));

        if (!$existing) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($existing) {
            $table->dropColumn(array_values($existing));
        });
    }
}
