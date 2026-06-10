<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kodzero_posmall_test_runs')) {
            return;
        }

        Schema::create('kodzero_posmall_test_runs', function ($table) {
            $table->increments('id');
            $table->string('trigger', 20)->default('manual');
            $table->integer('backend_user_id')->nullable();
            $table->string('backend_user_name')->nullable();
            $table->string('status', 20)->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('selected_tests_count')->default(0);
            $table->integer('backend_tests_count')->default(0);
            $table->integer('dusk_tests_count')->default(0);
            $table->text('selected_tests')->nullable();
            $table->text('failing_tests')->nullable();
            $table->string('log_path')->nullable();
            $table->text('error_output')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kodzero_posmall_test_runs');
    }
};

