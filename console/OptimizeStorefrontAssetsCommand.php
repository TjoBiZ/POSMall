<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Classes\PageSpeed\StorefrontAssetOptimizer;

class OptimizeStorefrontAssetsCommand extends Command
{
    protected $signature = 'posmall:pagespeed:optimize-assets
        {--mix-only : Fail instead of using the PHP fallback when Laravel Mix dependencies are missing}';

    protected $description = 'Generate POSMall PageSpeed storefront CSS/JS derivatives and manifest.';

    public function handle(StorefrontAssetOptimizer $optimizer): int
    {
        $result = $this->option('mix-only')
            ? $optimizer->optimizeWithLaravelMixOnly()
            : $optimizer->optimize();
        $rows = [];

        foreach ($result['assets'] as $type => $asset) {
            $rows[] = [
                strtoupper((string)$type),
                $asset['status'] ?? 'unknown',
                $asset['source_bytes'] ?? '-',
                $asset['compiled_bytes'] ?? '-',
                $asset['gzip_bytes'] ?? '-',
                $asset['version'] ?? '-',
            ];
        }

        $this->info('POSMall PageSpeed storefront assets generated.');
        $this->line('Builder: ' . ($result['builder'] ?? 'unknown'));
        $this->table(['Asset', 'Status', 'Source bytes', 'Compiled bytes', 'Gzip bytes', 'Version'], $rows);
        $this->line('Manifest: themes/' . $result['theme'] . '/' . $result['manifest']);

        return 0;
    }
}
