<?php

namespace KodZero\POSMall\Updates;

use October\Rain\Database\Updates\Migration;
use Schema;

class CreateKodZeroPOSMallReviews extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('kodzero_posmall_review_categories')) {
            Schema::create('kodzero_posmall_review_categories', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('slug')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (! Schema::hasTable('kodzero_posmall_category_review_category')) {
            Schema::create('kodzero_posmall_category_review_category', function ($table) {
                $table->increments('id');
                $table->integer('category_id');
                $table->integer('review_category_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['category_id', 'review_category_id'], 'kodzero_posmall_unq_review_category_id');
                $table->index(['category_id', 'review_category_id'], 'idx_kodzero_posmall_review_category_id');
            });
        }
        if (! Schema::hasTable('kodzero_posmall_reviews')) {
            Schema::create('kodzero_posmall_reviews', function ($table) {
                $table->increments('id');
                $table->integer('product_id')->index();
                $table->integer('variant_id')->nullable()->index();
                $table->integer('customer_id')->nullable();
                $table->tinyInteger('rating');
                $table->string('user_hash');
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->text('pros')->nullable();
                $table->text('cons')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('approved_at')->nullable();
            });
        }
        if (! Schema::hasTable('kodzero_posmall_category_reviews')) {
            Schema::create('kodzero_posmall_category_reviews', function ($table) {
                $table->increments('id');
                $table->integer('review_id')->index();
                $table->integer('review_category_id')->index();
                $table->tinyInteger('rating');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('approved_at')->nullable();
            });
        }
        if (! Schema::hasTable('kodzero_posmall_category_review_totals')) {
            Schema::create('kodzero_posmall_category_review_totals', function ($table) {
                $table->increments('id');
                $table->integer('product_id')->nullable()->index();
                $table->integer('variant_id')->nullable();
                $table->integer('review_category_id');
                $table->decimal('rating', 3, 2);
            });
        }
        Schema::table('kodzero_posmall_categories', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_categories', 'inherit_review_categories')) {
                $table->boolean('inherit_review_categories')->after('inherit_property_groups')->default(0);
            }
        });
        Schema::table('kodzero_posmall_products', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_products', 'reviews_rating')) {
                $table->decimal('reviews_rating', 3, 2)->after('stock')->default(0);
            }
        });
        Schema::table('kodzero_posmall_product_variants', function ($table) {
            if (! Schema::hasColumn('kodzero_posmall_product_variants', 'reviews_rating')) {
                $table->decimal('reviews_rating', 3, 2)->after('stock')->default(0);
            }
        });

        if (Schema::hasTable('kodzero_posmall_index')) {
            Schema::table('kodzero_posmall_index', function ($table) {
                if (! Schema::hasColumn('kodzero_posmall_index', 'reviews_rating')) {
                    $table->decimal('reviews_rating', 3, 2);
                }
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_review_categories');
        Schema::dropIfExists('kodzero_posmall_category_review_category');
        Schema::dropIfExists('kodzero_posmall_reviews');
        Schema::dropIfExists('kodzero_posmall_category_reviews');
        Schema::dropIfExists('kodzero_posmall_category_review_totals');
        $this->dropColumnsIfExist('kodzero_posmall_categories', ['inherit_review_categories']);
        $this->dropColumnsIfExist('kodzero_posmall_products', ['reviews_rating']);
        $this->dropColumnsIfExist('kodzero_posmall_product_variants', ['reviews_rating']);
    }

    protected function dropColumnsIfExist(string $tableName, array $columns): void
    {
        $existing = array_filter($columns, fn ($column) => Schema::hasColumn($tableName, $column));

        if (!$existing) {
            return;
        }

        Schema::table($tableName, function ($table) use ($existing) {
            $table->dropColumn(array_values($existing));
        });
    }
}
