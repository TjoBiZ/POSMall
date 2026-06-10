<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Images;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use System\Models\File as SystemFile;

class CatalogImageOptimizer
{
    public const PROFILE_CATALOG = 'catalog';
    public const PROFILE_PRODUCT = 'product';
    public const PROFILE_SERVICE = 'service';

    private const WIDTH = 480;
    private const HEIGHT = 360;
    private const QUALITY = 85;
    private const SOURCE_DIR = 'plugins/kodzero/posmall/updates/seeders/demo/images/products';
    private const CACHE_ROOT = 'storage/app/media/posmall/cache/images';
    private const PUBLIC_ROOT = '/storage/app/media/posmall/cache/images';
    private const METADATA_CACHE_PREFIX = 'kodzero.posmall.images.metadata.v2.';
    private const METADATA_CACHE_TTL_SECONDS = 900;
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];
    private const RASTER_CONTENT_TYPES = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
        'image/x-heic',
        'image/x-heif',
    ];

    private const PROFILES = [
        self::PROFILE_CATALOG => ['width' => 480, 'height' => 360],
        self::PROFILE_PRODUCT => ['width' => 960, 'height' => 720],
        self::PROFILE_SERVICE => ['width' => 480, 'height' => 360],
    ];

    private ?int $imageCount = null;

    private array $profileGeneratedCounts = [];

    private array $generatedNameMaps = [];

    public function catalogSources($item): array
    {
        $image = $item->image ?? null;
        if ($image) {
            $derived = $this->sourcesFromAttachedFile($image, (string)($item->name ?? 'POSMall catalog item'));
            if ($derived !== null) {
                return $derived;
            }

            return [
                'webp' => null,
                'jpeg' => $this->fallbackImageUrl($image, self::WIDTH, self::HEIGHT),
                'alt' => (string)($item->name ?? 'POSMall catalog item'),
            ];
        }

        $count = $this->generatedCount();
        if ($count < 1) {
            return [
                'webp' => null,
                'jpeg' => URL::asset($this->fallbackThemeAssetPath()),
                'alt' => (string)($item->name ?? 'POSMall catalog item'),
            ];
        }

        $sources = $this->sourceImages();
        $number = $this->imageNumberFor($item, $count);
        $name = isset($sources[$number - 1])
            ? $this->generatedRelativeBaseName(self::PROFILE_CATALOG, $number, $sources[$number - 1])
            : $this->generatedBaseName(self::PROFILE_CATALOG, $number);

        return [
            'webp' => URL::asset(self::PUBLIC_ROOT . '/' . self::PROFILE_CATALOG . '/webp/' . $name . '.webp'),
            'jpeg' => URL::asset(self::PUBLIC_ROOT . '/' . self::PROFILE_CATALOG . '/jpeg/' . $name . '.jpg'),
            'alt' => (string)($item->name ?? 'POSMall catalog item'),
        ];
    }

    public function sourcesFromFileName(?string $fileName, string $alt, string $profile = self::PROFILE_CATALOG): ?array
    {
        $base = $this->generatedBaseNameForFileName((string)$fileName, $profile);
        if ($base === null) {
            return null;
        }

        return [
            'webp' => URL::asset(self::PUBLIC_ROOT . '/' . $profile . '/webp/' . $base . '.webp'),
            'jpeg' => URL::asset(self::PUBLIC_ROOT . '/' . $profile . '/jpeg/' . $base . '.jpg'),
            'alt' => $alt,
        ];
    }

    public function imageSources($file, string $alt, string $profile = self::PROFILE_CATALOG): ?array
    {
        return $this->sourcesFromAttachedFile($file, $alt, $profile);
    }

    public function ensureOptimized(array $profiles = [self::PROFILE_CATALOG]): void
    {
        $profiles = $this->normalizeProfiles($profiles);
        foreach ($profiles as $profile) {
            if ($this->profileGeneratedCount($profile) > 0) {
                continue;
            }

            $this->optimize([$profile]);
        }
    }

    public function optimizeImageSet(int $imageSetId, array $profiles = ['all']): int
    {
        return $this->optimizeAttachmentQuery(
            SystemFile::query()
                ->where('attachment_type', 'posmall.imageset')
                ->where('attachment_id', $imageSetId)
                ->where('field', 'images'),
            $profiles
        );
    }

    public function optimizeAttachedModelImages(string $attachmentType, int $attachmentId, string $field = 'images', array $profiles = ['all']): int
    {
        return $this->optimizeAttachmentQuery(
            SystemFile::query()
                ->where('attachment_type', $attachmentType)
                ->where('attachment_id', $attachmentId)
                ->where('field', $field),
            $profiles
        );
    }

    public function optimize(array $profiles = [self::PROFILE_CATALOG]): array
    {
        $convert = $this->convertBinary();
        if ($convert === null) {
            throw new RuntimeException('ImageMagick convert is required to optimize POSMall catalog images.');
        }

        $sources = $this->sourceImages();
        $profiles = $this->normalizeProfiles($profiles);
        $this->clearObsoleteCacheRoots();

        $created = 0;
        foreach ($profiles as $profile) {
            $this->clearGeneratedCache($profile);
            $this->ensureCacheDirectories($profile);

            foreach ($sources as $index => $source) {
                $number = $index + 1;
                $meta = self::PROFILES[$profile];
                $relativeBase = $this->generatedRelativeBaseName($profile, $number, $source);
                $webp = base_path($this->cacheDir($profile) . '/webp/' . $relativeBase . '.webp');
                $jpeg = base_path($this->cacheDir($profile) . '/jpeg/' . $relativeBase . '.jpg');

                File::ensureDirectoryExists(dirname($webp), 0775, true);
                File::ensureDirectoryExists(dirname($jpeg), 0775, true);
                $this->runConvert($convert, $source, $webp, 'webp', $meta['width'], $meta['height']);
                $this->runConvert($convert, $source, $jpeg, 'jpeg', $meta['width'], $meta['height']);
                $created += 2;
            }
        }

        $attached = $this->optimizeAttachedImages($profiles);
        $created += $attached['created_files'];

        $this->imageCount = count($sources);

        return [
            'source_count' => count($sources),
            'attached_count' => $attached['source_count'],
            'created_files' => $created,
            'cache_dir' => base_path(self::CACHE_ROOT),
            'quality' => self::QUALITY,
            'profiles' => implode(', ', $profiles),
        ];
    }

    private function sourcesFromAttachedFile($file, string $alt, string $profile = self::PROFILE_CATALOG): ?array
    {
        if ($this->shouldUseDirectFile($file)) {
            return [
                'webp' => null,
                'jpeg' => $file->path,
                'alt' => $alt,
            ];
        }

        $fileName = (string)$file->file_name;
        if ($this->isSyntheticLoadFileName($fileName)) {
            $this->ensureOptimized([$profile]);
        }

        $fromSeedCache = $this->sourcesFromFileName($fileName, $alt, $profile);
        if ($fromSeedCache !== null) {
            return $fromSeedCache;
        }

        if ($this->isSyntheticLoadFileName($fileName)) {
            return null;
        }

        $relativeBase = $this->attachedRelativeBaseName($file, $profile);
        if ($relativeBase === null) {
            return null;
        }

        $jpeg = base_path($this->cacheDir($profile) . '/jpeg/' . $relativeBase . '.jpg');
        $webp = base_path($this->cacheDir($profile) . '/webp/' . $relativeBase . '.webp');
        if (!is_file($jpeg) || !is_file($webp)) {
            $this->optimizeAttachedFile($file, $profile);
        }

        if (!is_file($jpeg) && $this->isHeicFile($file)) {
            Log::warning('POSMall HEIC/HEIF image has no generated JPEG derivative.', [
                'file_id' => $file->id ?? null,
                'file_name' => $file->file_name ?? null,
                'disk_name' => $file->disk_name ?? null,
            ]);

            return [
                'webp' => null,
                'jpeg' => URL::asset($this->fallbackThemeAssetPath()),
                'alt' => $alt,
            ];
        }

        if (!is_file($jpeg)) {
            return [
                'webp' => null,
                'jpeg' => $file->path,
                'alt' => $alt,
            ];
        }

        return [
            'webp' => is_file($webp) ? URL::asset(self::PUBLIC_ROOT . '/' . $profile . '/webp/' . $relativeBase . '.webp') : null,
            'jpeg' => URL::asset(self::PUBLIC_ROOT . '/' . $profile . '/jpeg/' . $relativeBase . '.jpg'),
            'alt' => $alt,
        ];
    }

    private function shouldUseDirectFile($file): bool
    {
        if ($this->isHeicFile($file)) {
            return false;
        }

        $extension = strtolower((string)($file->extension ?? pathinfo((string)($file->file_name ?? ''), PATHINFO_EXTENSION)));
        return $extension === 'svg';
    }

    private function fallbackImageUrl($file, int $width, int $height): string
    {
        $extension = strtolower((string)($file->extension ?? pathinfo((string)($file->file_name ?? ''), PATHINFO_EXTENSION)));
        if ($extension === 'svg') {
            return (string)($file->path ?? $this->filePath($file));
        }

        if (method_exists($file, 'getThumbUrl')) {
            return (string)$file->getThumbUrl($width, $height, ['mode' => 'auto']);
        }

        if (method_exists($file, 'getThumb')) {
            return (string)$file->getThumb($width, $height, ['mode' => 'auto']);
        }

        return (string)($file->path ?? $this->filePath($file));
    }

    private function filePath($file): string
    {
        if (method_exists($file, 'getPath')) {
            return (string)$file->getPath();
        }

        return '';
    }

    private function isHeicFile($file): bool
    {
        $extension = strtolower((string)($file->extension ?? pathinfo((string)($file->file_name ?? ''), PATHINFO_EXTENSION)));
        $contentType = strtolower((string)($file->content_type ?? ''));

        return in_array($extension, ['heic', 'heif'], true)
            || in_array($contentType, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/x-heic', 'image/x-heif'], true);
    }

    private function optimizeAttachedImages(array $profiles): array
    {
        $files = SystemFile::query()
            ->whereIn('content_type', self::RASTER_CONTENT_TYPES)
            ->where('file_name', 'not like', '%-load-%')
            ->where('file_name', 'not like', 'posmall-load-%')
            ->where(function ($query): void {
                $query->where('attachment_type', 'posmall.imageset')
                    ->orWhereIn('attachment_type', [
                        'posmall.custom_field_option',
                        'KodZero\\POSMall\\Models\\Category',
                        'KodZero\\POSMall\\Models\\Service',
                    ]);
            })
            ->orderBy('id')
            ->get()
            ->unique('disk_name')
            ->values();

        $created = 0;
        foreach ($profiles as $profile) {
            foreach ($files as $file) {
                $created += $this->optimizeAttachedFile($file, $profile);
            }
        }

        return [
            'source_count' => $files->count(),
            'created_files' => $created,
        ];
    }

    private function optimizeAttachmentQuery($query, array $profiles): int
    {
        $profiles = $this->normalizeProfiles($profiles);
        $files = $query
            ->whereIn('content_type', self::RASTER_CONTENT_TYPES)
            ->get()
            ->unique('disk_name')
            ->values();

        $created = 0;
        foreach ($profiles as $profile) {
            foreach ($files as $file) {
                $created += $this->optimizeAttachedFile($file, $profile);
            }
        }

        return $created;
    }

    private function optimizeAttachedFile($file, string $profile): int
    {
        $relativeBase = $this->attachedRelativeBaseName($file, $profile);
        if ($relativeBase === null) {
            return 0;
        }

        if ($this->shouldUseDirectFile($file)) {
            return 0;
        }

        $source = $this->attachedLocalPath($file);
        if ($source === null) {
            return 0;
        }

        $convert = $this->convertBinary();
        if ($convert === null) {
            return 0;
        }

        $meta = self::PROFILES[$profile];
        $webp = base_path($this->cacheDir($profile) . '/webp/' . $relativeBase . '.webp');
        $jpeg = base_path($this->cacheDir($profile) . '/jpeg/' . $relativeBase . '.jpg');

        File::ensureDirectoryExists(dirname($webp), 0775, true);
        File::ensureDirectoryExists(dirname($jpeg), 0775, true);

        $created = 0;
        if (!is_file($webp)) {
            $created += $this->runConvertWithFallback($convert, $source, $webp, 'webp', $meta['width'], $meta['height']) ? 1 : 0;
        }
        if (!is_file($jpeg)) {
            $created += $this->runConvertWithFallback($convert, $source, $jpeg, 'jpeg', $meta['width'], $meta['height']) ? 1 : 0;
        }

        return $created;
    }

    private function attachedLocalPath($file): ?string
    {
        if (!method_exists($file, 'getLocalPath')) {
            return null;
        }

        try {
            $path = $file->getLocalPath();
        } catch (\Throwable) {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    private function attachedRelativeBaseName($file, string $profile): ?string
    {
        $fileName = (string)($file->file_name ?? '');
        $slug = $this->sourceSlugFromFileName($fileName);
        if ($slug === '') {
            return null;
        }

        $meta = self::PROFILES[$profile];
        $fileId = (int)($file->id ?? 0);
        $suffix = $fileId > 0 ? 'file-' . $fileId : substr((string)($file->disk_name ?? md5($fileName)), 0, 8);

        return sprintf('uploaded/%s-%s-%dx%d-%s', $slug, $profile, $meta['width'], $meta['height'], $suffix);
    }

    public function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    private function sourceImages(): array
    {
        $root = base_path(self::SOURCE_DIR);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::RASTER_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files, SORT_NATURAL);

        return $files;
    }

    private function ensureCacheDirectories(string $profile): void
    {
        File::ensureDirectoryExists(base_path($this->cacheDir($profile) . '/webp'), 0775, true);
        File::ensureDirectoryExists(base_path($this->cacheDir($profile) . '/jpeg'), 0775, true);
    }

    private function clearGeneratedCache(string $profile): void
    {
        $directory = base_path($this->cacheDir($profile));
        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        unset($this->profileGeneratedCounts[$profile]);
        unset($this->generatedNameMaps[$profile]);
        Cache::forget($this->profileGeneratedCountCacheKey($profile));
        Cache::forget($this->generatedNameMapCacheKey($profile));
        if ($profile === self::PROFILE_CATALOG) {
            $this->imageCount = null;
        }
    }

    private function clearObsoleteCacheRoots(): void
    {
        $oldFlatCatalog = base_path('storage/app/media/posmall/cache/catalog');
        if (is_dir($oldFlatCatalog)) {
            File::deleteDirectory($oldFlatCatalog);
        }
    }

    private function runConvert(string $convert, string $source, string $target, string $format, int $width, int $height): void
    {
        $quality = (string)self::QUALITY;
        $resize = $width . 'x' . $height;

        $arguments = [
            $convert,
            $source,
            '-auto-orient',
            '-resize',
            $resize,
            '-strip',
        ];

        if ($format === 'jpeg') {
            $arguments[] = '-sampling-factor';
            $arguments[] = '4:2:0';
            $arguments[] = '-interlace';
            $arguments[] = 'Plane';
        }

        $arguments[] = '-quality';
        $arguments[] = $quality;
        $arguments[] = $target;

        $process = new Process($arguments);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful() || !is_file($target)) {
            throw new RuntimeException('Failed to optimize catalog image: ' . $source);
        }
    }

    private function convertBinary(): ?string
    {
        return (new ExecutableFinder())->find('convert');
    }

    private function runConvertSafe(string $convert, string $source, string $target, string $format, int $width, int $height): bool
    {
        try {
            $this->runConvert($convert, $source, $target, $format, $width, $height);

            return true;
        } catch (\Throwable $exception) {
            if (is_file($target)) {
                File::delete($target);
            }

            Log::warning('POSMall image derivative generation failed.', [
                'source' => $source,
                'target' => $target,
                'format' => $format,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function runConvertWithFallback(string $convert, string $source, string $target, string $format, int $width, int $height): bool
    {
        if ($this->runConvertSafe($convert, $source, $target, $format, $width, $height)) {
            return true;
        }

        $fallback = $this->fallbackSourcePath();
        if ($fallback === null || $fallback === $source) {
            return false;
        }

        return $this->runConvertSafe($convert, $fallback, $target, $format, $width, $height);
    }

    private function fallbackSourcePath(): ?string
    {
        $fallback = base_path(ltrim($this->fallbackThemeAssetPath(), '/'));

        return is_file($fallback) ? $fallback : null;
    }

    private function fallbackThemeAssetPath(): string
    {
        $asset = 'assets/posmall/img/wings-demo/purple-wings-shawl.jpg';
        $candidates = [
            'kodzero-posmalltheme',
            'kodzero-posmall',
            'POSMall',
            'posmalltheme',
            'posmall',
        ];

        foreach ($candidates as $themePath) {
            $relative = 'themes/' . $themePath . '/' . $asset;
            if (is_file(base_path($relative))) {
                return '/' . $relative;
            }
        }

        return '/themes/kodzero-posmalltheme/' . $asset;
    }

    private function generatedCount(): int
    {
        if ($this->imageCount !== null) {
            return $this->imageCount;
        }

        $this->imageCount = $this->profileGeneratedCount(self::PROFILE_CATALOG);

        return $this->imageCount;
    }

    private function profileGeneratedCount(string $profile): int
    {
        if (array_key_exists($profile, $this->profileGeneratedCounts)) {
            return $this->profileGeneratedCounts[$profile];
        }

        return $this->profileGeneratedCounts[$profile] = (int)Cache::remember(
            $this->profileGeneratedCountCacheKey($profile),
            self::METADATA_CACHE_TTL_SECONDS,
            function () use ($profile): int {
                return $this->countGeneratedProfileFiles($profile);
            }
        );
    }

    private function countGeneratedProfileFiles(string $profile): int
    {
        $directory = base_path($this->cacheDir($profile) . '/webp');
        if (!is_dir($directory)) {
            return 0;
        }

        return collect(File::allFiles($directory))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'webp')
            ->reject(fn ($file) => $this->isUploadedCacheFile($file, $directory))
            ->count();
    }

    private function normalizeProfiles(array $profiles): array
    {
        $profiles = array_values(array_unique($profiles));
        if ($profiles === ['all']) {
            return $this->profiles();
        }

        foreach ($profiles as $profile) {
            if (!isset(self::PROFILES[$profile])) {
                throw new RuntimeException('Unknown POSMall image optimization profile: ' . $profile);
            }
        }

        return $profiles;
    }

    private function cacheDir(string $profile): string
    {
        return self::CACHE_ROOT . '/' . $profile;
    }

    private function imageNumberFor($item, int $count): int
    {
        $key = (string)($item->id ?? $item->product_id ?? $item->slug ?? $item->name ?? '1');

        return (abs(crc32($key)) % $count) + 1;
    }

    private function generatedBaseName(string $profile, int $number, ?string $source = null): string
    {
        static $names = [];

        if ($source === null && isset($names[$profile][$number])) {
            return $names[$profile][$number];
        }

        $source ??= $this->sourceImages()[$number - 1] ?? ('image-' . $number);
        $stem = pathinfo($source, PATHINFO_FILENAME);
        $slug = Str::slug($stem) ?: 'image';
        $meta = self::PROFILES[$profile] ?? self::PROFILES[self::PROFILE_CATALOG];
        $base = sprintf('%s-%s-%dx%d-%03d', $slug, $profile, $meta['width'], $meta['height'], $number);

        $names[$profile][$number] = $base;

        return $base;
    }

    private function generatedRelativeBaseName(string $profile, int $number, string $source): string
    {
        $relativeDir = $this->sourceRelativeDirectory($source);
        $base = $this->generatedBaseName($profile, $number, $source);

        return $relativeDir === '' ? $base : $relativeDir . '/' . $base;
    }

    private function sourceRelativeDirectory(string $source): string
    {
        $root = rtrim(base_path(self::SOURCE_DIR), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $directory = dirname($source);

        if (!str_starts_with($directory . DIRECTORY_SEPARATOR, $root)) {
            return '';
        }

        $relative = trim(substr($directory, strlen($root)), DIRECTORY_SEPARATOR);
        if ($relative === '') {
            return '';
        }

        return collect(explode(DIRECTORY_SEPARATOR, $relative))
            ->map(fn (string $part) => Str::slug($part) ?: 'group')
            ->implode('/');
    }

    private function generatedBaseNameForFileName(string $fileName, string $profile): ?string
    {
        $slug = $this->sourceSlugFromFileName($fileName);
        if ($slug === '') {
            return null;
        }

        $map = $this->generatedNameMap($profile);

        return $map[$slug] ?? null;
    }

    private function sourceSlugFromFileName(string $fileName): string
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $stem = preg_replace('~-load-\d+$~', '', $stem) ?: $stem;

        return Str::slug($stem);
    }

    private function isSyntheticLoadFileName(string $fileName): bool
    {
        return str_starts_with($fileName, 'posmall-load-') || preg_match('~-load-\d+\.[a-z0-9]+$~i', $fileName) === 1;
    }

    private function generatedNameMap(string $profile): array
    {
        if (isset($this->generatedNameMaps[$profile])) {
            return $this->generatedNameMaps[$profile];
        }

        $this->generatedNameMaps[$profile] = Cache::remember($this->generatedNameMapCacheKey($profile), self::METADATA_CACHE_TTL_SECONDS, function () use ($profile): array {
            $map = [];
            $directory = base_path($this->cacheDir($profile) . '/jpeg');
            if (!is_dir($directory)) {
                return $map;
            }

            foreach (File::allFiles($directory) as $file) {
                if (strtolower($file->getExtension()) !== 'jpg') {
                    continue;
                }

                if ($this->isUploadedCacheFile($file, $directory)) {
                    continue;
                }

                $base = pathinfo($file->getPathname(), PATHINFO_FILENAME);
                $needle = '-' . $profile . '-';
                $position = strrpos($base, $needle);
                if ($position === false) {
                    continue;
                }

                $relative = trim(substr($file->getPathname(), strlen($directory . DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR);
                $relativeBase = preg_replace('~\.jpg$~', '', $relative) ?: $base;

                $map[substr($base, 0, $position)] = str_replace(DIRECTORY_SEPARATOR, '/', $relativeBase);
            }

            return $map;
        });

        return $this->generatedNameMaps[$profile];
    }

    private function isUploadedCacheFile($file, string $directory): bool
    {
        $relative = trim(substr($file->getPathname(), strlen($directory . DIRECTORY_SEPARATOR)), DIRECTORY_SEPARATOR);

        return str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $relative), 'uploaded/');
    }

    private function profileGeneratedCountCacheKey(string $profile): string
    {
        return self::METADATA_CACHE_PREFIX . 'generated_count.' . $profile;
    }

    private function generatedNameMapCacheKey(string $profile): string
    {
        return self::METADATA_CACHE_PREFIX . 'generated_name_map.' . $profile;
    }
}
