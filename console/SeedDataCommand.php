<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use DB;
use Exception;
use Illuminate\Console\Command;
use RuntimeException;
use System\Models\File as SystemFile;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\Noop;
use KodZero\POSMall\Classes\Index\ProductEntry;
use KodZero\POSMall\Classes\Index\VariantEntry;
use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\CustomFieldOption;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\ImageSet;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ProductFile;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\ServiceOption;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\ShippingMethodRate;
use KodZero\POSMall\Models\Variant;
use KodZero\POSMall\Classes\Security\BackendAdminSafety;
use KodZero\POSMall\Updates\Seeders\POSMallSeeder;
use System;

class SeedDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = '
        posmall:seed 
        {--force : Don\'t ask before seeding records}
        {--refresh : Run October plugin:refresh before seeding. This drops and recreates POSMall plugin tables.}
        {--d|with-demo : Insert demonstration records, such as products}
        {--l|locale= : Force a specific locale for the seeded records} 
    ';

    /**
     * The console command name.
     * @var string
     */
    protected $name = 'posmall:seed';

    /**
     * The console command description.
     * @var string|null
     */
    protected $description = 'Seed POSMall related database records.';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $question = $this->option('refresh')
            ? 'This will refresh POSMall plugin tables before seeding. Existing POSMall table data can be lost. Continue?'
            : 'Seed missing POSMall core lookup records without refreshing plugin tables?';

        if (!$this->option('force') && !$this->confirm($question, false)) {
            return 0;
        }

        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:seed before seed/refresh');
        
        $demo = $this->option('with-demo');
        if ($demo) {
            $this->error('The legacy POSMall demo catalog seed is disabled.');
            $this->line('Use the single supported catalog seed instead: php artisan posmall:seed-wings-of-win --force');

            return 1;
        }

        $question = 'Would you also like to import the demo content?';

        if (!$this->option('force') && !$demo && $this->confirm($question, false)) {
            $this->error('The legacy POSMall demo catalog seed is disabled.');
            $this->line('Use the single supported catalog seed instead: php artisan posmall:seed-wings-of-win --force');

            return 1;
        }
        
        // Force locale
        $locale = $this->option('locale');

        if (!empty($locale)) {
            app()->setLocale($locale);
        }

        // Use a Noop-Indexer so no unnecessary queries are run during seeding.
        // The index will be re-built once everything is done.
        $originalIndex = app(Index::class);
        app()->bind(Index::class, fn () => new Noop());

        if ($this->option('refresh')) {
            $this->output->newLine();
            $this->warn(' Refreshing POSMall plugin tables...');

            try {
                $this->cleanup();
                $this->info('Refresh successful.');
            } catch (Exception $exc) {
                $this->output->block('The following error occurred.', 'ERROR', 'fg=red');
                $this->error($exc->getMessage());

                return 1;
            }
        } else {
            $this->line('Plugin table refresh skipped. Existing POSMall data will be preserved.');
        }
        $this->output->newLine();

        // Seed core records
        $this->warn(' Seed core database records...');

        try {
            if (version_compare(System::VERSION, '3.0', '<')) {
                app()->call(POSMallSeeder::class);
            } else {
                $exitCode = $this->callSilent('plugin:seed', [
                    'namespace' => 'KodZero.POSMall',
                    'class'     => 'KodZero\POSMall\Updates\Seeders\POSMallSeeder',
                ]);

                if ($exitCode !== 0) {
                    throw new RuntimeException('POSMall core seeder failed with exit code ' . $exitCode . '.');
                }
            }
            $this->info('Seed core records successful.');
        } catch (Exception $exc) {
            $this->output->block('The following error occurred.', 'ERROR', 'fg=red');
            $this->error($exc->getMessage());

            return 1;
        }
        $this->output->newLine();

        $this->warn(' Demo catalog import skipped.');
        $this->line('Use the single supported catalog seed: php artisan posmall:seed-wings-of-win --force');
        $this->output->newLine();

        // Re-Index all products
        $this->warn(' Re-Create products index...');

        try {
            app()->bind(Index::class, fn () => $originalIndex);
            $exitCode = $this->callSilent('posmall:index', ['--force' => true]);

            if ($exitCode !== 0) {
                throw new RuntimeException('POSMall index rebuild failed with exit code ' . $exitCode . '.');
            }

            $this->info('Re-Index products successful.');
        } catch (Exception $exc) {
            $this->output->block('The following error occurred.', 'ERROR', 'fg=red');
            $this->error($exc->getMessage() . "\n" . $exc->getFile());

            return 1;
        }
        $this->output->newLine();

        // Finish
        app(BackendAdminSafety::class)->assertRealBackendSuperuserAvailable('posmall:seed after seed/index');
        $this->alert('Ready to go, happy selling!');
    }

    /**
     * Cleanup and reset POSMall plugin.
     * @return void
     */
    protected function cleanup()
    {
        try {
            if (version_compare(System::VERSION, '3.0', '<')) {
                $exitCode = $this->callSilent('plugin:refresh', [
                    'name'          => 'KodZero.POSMall',
                    '--force'       => true,
                    '--quiet'       => true,
                ]);
            } else {
                $exitCode = $this->callSilent('plugin:refresh', [
                    'namespace'     => 'KodZero.POSMall',
                    '--force'       => true,
                    '--quiet'       => true,
                ]);
            }

            if ($exitCode !== 0) {
                throw new RuntimeException('POSMall plugin refresh failed with exit code ' . $exitCode . '.');
            }

            $this->callSilent('cache:clear', []);
    
            SystemFile::where(function ($query) {
                $query->whereIn('attachment_type', $this->posmallAttachmentTypes())
                    ->orWhere('attachment_type', 'LIKE', 'KodZero\\POSMall\\%');
            })
                ->get()
                ->each
                ->delete();
    
            // Clean Indexes
            $index = app(Index::class);
            $index->drop(ProductEntry::INDEX);
            $index->drop(VariantEntry::INDEX);
        } catch (Exception $exc) {
            throw $exc;
        }
    }

    private function posmallAttachmentTypes(): array
    {
        return array_filter(array_unique([
            CustomField::MORPH_KEY,
            CustomFieldOption::MORPH_KEY,
            Discount::MORPH_KEY,
            ImageSet::MORPH_KEY,
            PaymentMethod::MORPH_KEY,
            Product::MORPH_KEY,
            ProductFile::class,
            Service::class,
            ServiceOption::MORPH_KEY,
            ShippingMethod::MORPH_KEY,
            ShippingMethodRate::MORPH_KEY,
            Variant::MORPH_KEY,
        ]));
    }
}
