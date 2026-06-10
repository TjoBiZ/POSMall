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
        if (!Schema::hasTable('kodzero_posmall_api_tokens')) {
            Schema::create('kodzero_posmall_api_tokens', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->string('token_hash', 64)->unique();
                $table->jsonb('scopes')->nullable();
                $table->jsonb('allowed_origins')->nullable();
                $table->unsignedInteger('rate_limit_per_minute')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['revoked_at', 'expires_at'], 'idx_kodzero_posmall_api_tokens_state');
            });
        }

        if (!Schema::hasTable('kodzero_posmall_vendors')) {
            Schema::create('kodzero_posmall_vendors', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->string('slug', 191)->unique();
                $table->string('contact_email', 191)->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('kodzero_posmall_channels')) {
            Schema::create('kodzero_posmall_channels', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->string('slug', 191)->unique();
                $table->string('type', 64)->default('storefront');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('kodzero_posmall_warehouses')) {
            Schema::create('kodzero_posmall_warehouses', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 191);
                $table->string('slug', 191)->unique();
                $table->string('type', 64)->default('default');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kodzero_posmall_warehouses');
        Schema::dropIfExists('kodzero_posmall_channels');
        Schema::dropIfExists('kodzero_posmall_vendors');
        Schema::dropIfExists('kodzero_posmall_api_tokens');
    }
};
