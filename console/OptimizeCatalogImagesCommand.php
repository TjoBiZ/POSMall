<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;

class OptimizeCatalogImagesCommand extends Command
{
    protected $signature = 'posmall:images:optimize-catalog
        {--profile=catalog : Image derivative profile: catalog, product, service or all}';

    protected $description = 'Generate POSMall PageSpeed image derivatives in public storage cache.';

    public function handle(CatalogImageOptimizer $optimizer): int
    {
        $profile = (string)$this->option('profile');
        $result = $optimizer->optimize([$profile]);
        PublicStorefrontCache::bumpVersion();

        $this->info('POSMall image cache generated. Originals were read-only and were not modified.');
        $this->table(['Metric', 'Value'], [
            ['Packaged source images', $result['source_count']],
            ['Uploaded attachment images', $result['attached_count']],
            ['Generated files', $result['created_files']],
            ['Profiles', $result['profiles']],
            ['Quality', $result['quality']],
            ['JPEG flags', 'strip metadata, progressive, 4:2:0 sampling'],
            ['WebP flags', 'strip metadata, quality 85'],
            ['Cache directory', $result['cache_dir']],
        ]);

        return 0;
    }
}
