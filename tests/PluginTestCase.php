<?php

declare(strict_types=1);

namespace KodZero\POSMall\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\WithFaker;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\Noop;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Updates\Seeders\POSMallSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\CustomerGroupTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\CustomerTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\CustomFieldTableSeeder;
use KodZero\POSMall\Updates\Seeders\Tables\ProductTableSeeder;
use System;
use System\Classes\PluginManager;

class PluginTestCase extends \PluginTestCase
{
    use RefreshDatabase;
    use WithFaker;

    /**
     * Setup the test environment.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        // Seed demo data
        if (version_compare(System::VERSION, '3.0', '<')) {
            $manager = PluginManager::instance();
            $manager->loadPlugins();
            $plugin = $manager->findByIdentifier('kodzero.posmall');
            $manager->registerPlugin($plugin);

            app()->call(POSMallSeeder::class);
            app()->call(CustomerGroupTableSeeder::class);
            app()->call(CustomerTableSeeder::class);
            app()->call(CustomFieldTableSeeder::class);
            app()->call(ProductTableSeeder::class);
        } else {
            \Artisan::call('plugin:seed', [
                'namespace' => 'KodZero.POSMall',
                'class'     => 'KodZero\POSMall\Updates\Seeders\POSMallSeeder',
            ]);
    
            //@todo temporary solution to fix testing
            \Artisan::call('plugin:seed', [
                'namespace' => 'KodZero.POSMall',
                'class'     => 'KodZero\POSMall\Updates\Seeders\Tables\CustomerGroupTableSeeder',
            ]);
    
            //@todo temporary solution to fix testing
            \Artisan::call('plugin:seed', [
                'namespace' => 'KodZero.POSMall',
                'class'     => 'KodZero\POSMall\Updates\Seeders\Tables\CustomerTableSeeder',
            ]);
    
            //@todo temporary solution to fix testing
            \Artisan::call('plugin:seed', [
                'namespace' => 'KodZero.POSMall',
                'class'     => 'KodZero\POSMall\Updates\Seeders\Tables\CustomFieldTableSeeder',
            ]);
    
            //@todo temporary solution to fix testing
            \Artisan::call('plugin:seed', [
                'namespace' => 'KodZero.POSMall',
                'class'     => 'KodZero\POSMall\Updates\Seeders\Tables\ProductTableSeeder',
            ]);
        }

        // Set CHF as default currency
        Currency::setActiveCurrency(Currency::where('code', 'CHF')->first());

        // Bind No-Op Index
        app()->bind(Index::class, fn () => new Noop());
    }

    /**
     * October's plugin test case rolls the current plugin and dependencies back
     * and forward during setUp(). Running that inside Laravel's test transaction
     * leaves PostgreSQL in an aborted transaction after the first DDL error.
     */
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();

            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }
    }

    /**
     * Tear down the test environment.
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
    }

    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, 'OFFLINE\\Mall\\')) {
                continue;
            }

            if ($class === \October\Rain\Database\Pivot::class || str_starts_with($class, 'Mockery_')) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (
                !$reflection->isInstantiable()
                || !$reflection->isSubclassOf(\October\Rain\Database\Model::class)
                || $reflection->isSubclassOf(\October\Rain\Database\Pivot::class)
                || $reflection->isSubclassOf(\PHPUnit\Framework\MockObject\MockObject::class)
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        \October\Rain\Database\Model::flushEventListeners();
    }

    protected function currencyId(string $code): int
    {
        return Currency::where('code', $code)->firstOrFail()->id;
    }
}
