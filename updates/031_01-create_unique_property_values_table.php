<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates;

use DB;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\UniquePropertyValue;
use Schema;

/**
 * CreateUniquePropertyValuesTable Migration
 *
 * @link https://docs.octobercms.com/3.x/extend/database/structure.html
 */
class CreateUniquePropertyValuesTable_031_01 extends Migration
{
    /**
     * Install Migration
     *
     * @return void
     */
    public function up()
    {
        Schema::create('kodzero_posmall_unique_property_values', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('property_value_id');
            $table->integer('property_id');
            $table->integer('category_id');
            $table->text('value')->nullable();
            $table->text('index_value')->nullable();

            if (!app()->runningUnitTests()) {
                $table->index(['property_id', 'category_id'], 'idx_kodzero_posmall_property_values_categories');
            }
        });

        DB::transaction(function () {
            foreach (Category::all() as $category) {
                UniquePropertyValue::resetForCategory($category);
            }
        });
    }

    /**
     * Uninstall Migration
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('kodzero_posmall_unique_property_values');
    }
};
