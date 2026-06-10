<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders;

use October\Rain\Database\Updates\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @return void
     */
    public function run()
    {
        throw new \RuntimeException(
            'The legacy POSMall demo catalog seed is disabled. Use: php artisan posmall:seed-wings-of-win --force'
        );
    }
}
