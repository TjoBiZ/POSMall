<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kodzero_posmall_load_benchmark_runs')) {
            return;
        }

        Schema::create('kodzero_posmall_load_benchmark_runs', function ($table) {
            $table->increments('id');
            $table->integer('target_records');
            $table->integer('actual_products')->default(0);
            $table->integer('actual_services')->default(0);
            $table->integer('actual_index_rows')->default(0);
            $table->integer('iterations')->default(10);
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('seed_seconds')->nullable();
            $table->integer('benchmark_seconds')->nullable();
            $table->decimal('category_avg_ms', 10, 3)->nullable();
            $table->decimal('filtered_avg_ms', 10, 3)->nullable();
            $table->decimal('search_avg_ms', 10, 3)->nullable();
            $table->decimal('memory_peak_mb', 10, 2)->nullable();
            $table->text('metrics')->nullable();
            $table->text('explain_plans')->nullable();
            $table->text('error_output')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kodzero_posmall_load_benchmark_runs');
    }
};
