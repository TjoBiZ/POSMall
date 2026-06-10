<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\PageSpeed;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class StorefrontAssetOptimizer
{
    private const THEME_NAME = 'POSMall';
    private const THEME_PATH_CANDIDATES = [
        'kodzero-posmalltheme',
        'kodzero-posmall',
        'POSMall',
        'posmalltheme',
        'posmall',
    ];
    private const MANIFEST_PATH = 'assets/posmall/compiled/manifest.json';

    private ?array $manifest = null;
    private ?bool $optimizedAssetsEnabled = null;

    private const ASSETS = [
        'css' => [
            'source' => 'assets/posmall/css/storefront.css',
            'compiled' => 'assets/posmall/compiled/css/storefront.min.css',
        ],
        'js' => [
            'source' => 'assets/posmall/js/storefront.js',
            'compiled' => 'assets/posmall/compiled/js/storefront.min.js',
        ],
    ];

    public function optimize(): array
    {
        $mixResult = $this->runLaravelMix();

        if ($mixResult['status'] === 'optimized') {
            return $mixResult;
        }

        return $this->optimizeWithPhpFallback($mixResult['message']);
    }

    public function optimizeWithLaravelMixOnly(): array
    {
        $result = $this->runLaravelMix();

        if ($result['status'] !== 'optimized') {
            throw new \RuntimeException($result['message']);
        }

        return $result;
    }

    public function optimizeWithPhpFallback(string $fallbackReason = ''): array
    {
        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'builder' => 'php-fallback',
            'message' => $fallbackReason,
            'assets' => [],
        ];

        foreach (self::ASSETS as $type => $asset) {
            $sourcePath = $this->absoluteThemePath($asset['source']);
            $compiledPath = $this->absoluteThemePath($asset['compiled']);

            if (!is_file($sourcePath)) {
                $manifest['assets'][$type] = [
                    'source' => $asset['source'],
                    'compiled' => $asset['compiled'],
                    'status' => 'missing-source',
                ];
                continue;
            }

            $source = (string)file_get_contents($sourcePath);
            $compiled = $type === 'css'
                ? $this->minifyCss($source)
                : $this->minifyJs($source);

            File::ensureDirectoryExists(dirname($compiledPath));
            file_put_contents($compiledPath, $compiled);
            $this->writeGzipSibling($compiledPath, $compiled);

            $manifest['assets'][$type] = [
                'source' => $asset['source'],
                'compiled' => $asset['compiled'],
                'status' => 'optimized',
                'source_bytes' => strlen($source),
                'compiled_bytes' => strlen($compiled),
                'gzip_bytes' => $this->gzipSize($compiled),
                'version' => substr(hash('sha256', $compiled), 0, 12),
            ];
        }

        $manifestPath = $this->absoluteThemePath(self::MANIFEST_PATH);
        File::ensureDirectoryExists(dirname($manifestPath));
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        $this->manifest = $manifest;

        return [
            'theme' => self::THEME_NAME,
            'manifest' => self::MANIFEST_PATH,
            'builder' => 'php-fallback',
            'assets' => $manifest['assets'],
        ];
    }

    public function assetPath(string $type): string
    {
        $asset = self::ASSETS[$type] ?? null;

        if (!$asset) {
            return '';
        }

        if (!$this->optimizedAssetsEnabled()) {
            return $asset['source'];
        }

        return is_file($this->absoluteThemePath($asset['compiled']))
            ? $asset['compiled']
            : $asset['source'];
    }

    public function assetVersion(string $type): string
    {
        if (!$this->optimizedAssetsEnabled()) {
            $asset = self::ASSETS[$type] ?? null;
            $path = $asset ? $this->absoluteThemePath($asset['source']) : '';

            return is_file($path) ? (string)filemtime($path) : 'dev';
        }

        $manifest = $this->manifest();
        $version = $manifest['assets'][$type]['version'] ?? null;

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $path = $this->absoluteThemePath($this->assetPath($type));

        return is_file($path) ? (string)filemtime($path) : 'dev';
    }

    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = $this->absoluteThemePath(self::MANIFEST_PATH);

        if (!is_file($manifestPath)) {
            return $this->manifest = [];
        }

        $data = json_decode((string)file_get_contents($manifestPath), true);

        return $this->manifest = is_array($data) ? $data : [];
    }

    public function optimizedAssetsEnabled(): bool
    {
        if ($this->optimizedAssetsEnabled !== null) {
            return $this->optimizedAssetsEnabled;
        }

        return $this->optimizedAssetsEnabled = (bool)\KodZero\POSMall\Models\GeneralSettings::get(
            'storefront_assets_optimized',
            false
        );
    }

    private function runLaravelMix(): array
    {
        $workingDirectory = $this->absoluteThemePath('assets');
        $packageJson = $workingDirectory . '/package.json';

        if (!is_file($packageJson)) {
            return [
                'status' => 'fallback',
                'message' => 'Laravel Mix package.json is missing.',
            ];
        }

        if (!is_dir($workingDirectory . '/node_modules')) {
            return [
                'status' => 'fallback',
                'message' => 'Laravel Mix dependencies are not installed. Run npm install in the POSMall theme assets directory for Webpack builds.',
            ];
        }

        $process = new Process(['npm', 'run', 'prod'], $workingDirectory, null, null, 120);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'status' => 'fallback',
                'message' => trim($process->getErrorOutput() ?: $process->getOutput()),
            ];
        }

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'builder' => 'laravel-mix',
            'message' => 'Compiled with Laravel Mix.',
            'assets' => [],
        ];

        foreach (self::ASSETS as $type => $asset) {
            $compiledPath = $this->absoluteThemePath($asset['compiled']);
            $sourcePath = $this->absoluteThemePath($asset['source']);
            $content = is_file($compiledPath) ? (string)file_get_contents($compiledPath) : '';

            if ($content !== '') {
                $this->writeGzipSibling($compiledPath, $content);
            }

            $manifest['assets'][$type] = [
                'source' => $asset['source'],
                'compiled' => $asset['compiled'],
                'status' => is_file($compiledPath) ? 'optimized' : 'missing-compiled',
                'source_bytes' => is_file($sourcePath) ? filesize($sourcePath) : null,
                'compiled_bytes' => is_file($compiledPath) ? filesize($compiledPath) : null,
                'gzip_bytes' => $content !== '' ? $this->gzipSize($content) : null,
                'version' => $content !== '' ? substr(hash('sha256', $content), 0, 12) : null,
            ];
        }

        $manifestPath = $this->absoluteThemePath(self::MANIFEST_PATH);
        File::ensureDirectoryExists(dirname($manifestPath));
        file_put_contents(
            $manifestPath,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
        $this->manifest = $manifest;

        return [
            'status' => 'optimized',
            'theme' => self::THEME_NAME,
            'manifest' => self::MANIFEST_PATH,
            'builder' => 'laravel-mix',
            'assets' => $manifest['assets'],
        ];
    }

    private function minifyCss(string $css): string
    {
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css) ?? $css;
        $css = str_replace([';}', ' 0px', ' 0rem'], ['}', ' 0', ' 0'], $css);

        return trim($css) . PHP_EOL;
    }

    private function minifyJs(string $js): string
    {
        $js = $this->stripJavaScriptComments($js);
        $lines = array_map('trim', preg_split('/\R/', $js) ?: []);
        $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== ''));

        return implode("\n", $lines) . PHP_EOL;
    }

    private function stripJavaScriptComments(string $js): string
    {
        $output = '';
        $length = strlen($js);
        $state = 'code';

        for ($i = 0; $i < $length; $i++) {
            $char = $js[$i];
            $next = $js[$i + 1] ?? '';

            if ($state === 'code') {
                if ($char === '"' || $char === "'" || $char === '`') {
                    $state = $char;
                    $output .= $char;
                    continue;
                }

                if ($char === '/' && $next === '/') {
                    while ($i < $length && !in_array($js[$i], ["\n", "\r"], true)) {
                        $i++;
                    }
                    $output .= "\n";
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $i += 2;
                    while ($i < $length - 1 && !($js[$i] === '*' && $js[$i + 1] === '/')) {
                        $i++;
                    }
                    $i++;
                    continue;
                }

                $output .= $char;
                continue;
            }

            $output .= $char;

            if ($char === '\\') {
                if ($i + 1 < $length) {
                    $output .= $js[++$i];
                }
                continue;
            }

            if ($char === $state) {
                $state = 'code';
            }
        }

        return $output;
    }

    private function writeGzipSibling(string $path, string $content): void
    {
        if (!function_exists('gzencode')) {
            return;
        }

        file_put_contents($path . '.gz', gzencode($content, 9));
    }

    private function gzipSize(string $content): ?int
    {
        if (!function_exists('gzencode')) {
            return null;
        }

        return strlen(gzencode($content, 9));
    }

    private function absoluteThemePath(string $relative): string
    {
        return $this->themeRootPath() . '/' . ltrim($relative, '/');
    }

    private function themeRootPath(): string
    {
        foreach (self::THEME_PATH_CANDIDATES as $themePath) {
            $root = base_path('themes/' . $themePath);
            if (is_file($root . '/theme.yaml') || is_dir($root . '/assets/posmall')) {
                return $root;
            }
        }

        return base_path('themes/' . self::THEME_NAME);
    }
}
