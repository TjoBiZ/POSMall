<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;
use ZipArchive;

class SstTaxSourceParser extends SeedRuleSourceParser
{
    private const RATE_TYPE_STATE = '45';
    private const RATE_TYPE_COUNTY = '00';
    private const RATE_TYPE_SPECIAL = '69';

    public function parse(array $source, string $stateCode): array
    {
        $stateCode = strtoupper($stateCode);

        if (($source['code'] ?? null) !== 'SST_RATES_BOUNDARIES' || !empty($source['starter_only'])) {
            return parent::parse($source, $stateCode);
        }

        $rateFile = $source['rate_file'] ?? $this->latestFileUrl($source, $stateCode, 'R', 'Rates');
        $boundaryFile = $source['boundary_file'] ?? $this->latestFileUrl($source, $stateCode, 'B', 'Boundary');

        if (!$rateFile || !$boundaryFile || (app()->runningUnitTests() && empty($source['rate_file']))) {
            return parent::parse($source, $stateCode);
        }

        $rates = $this->rates($rateFile);
        $stateRate = $rates[$this->rateKey(self::RATE_TYPE_STATE, $this->stateFipsFor($stateCode))] ?? null;

        if ($stateRate === null) {
            return parent::parse($source, $stateCode);
        }

        $regions = $this->regions($boundaryFile, $rates, $stateCode, $stateRate, (int)($source['sst_boundary_max_rows'] ?? 250000));

        if (!$regions) {
            return parent::parse($source, $stateCode);
        }

        $group = UsaTaxSourceRegistry::taxGroups()['PHYSICAL_TPP'];

        return collect($regions)
            ->map(function (array $region) use ($source, $rateFile, $boundaryFile, $stateCode, $group) {
                [$groupName, $groupDescription] = $group;
                $rate = round($region['rate_percent'], 4);

                return [
                    'state_code' => $stateCode,
                    'source_url' => $rateFile,
                    'source_type' => 'SST_RATE_BOUNDARY',
                    'source_name' => $source['name'] ?? 'SST Rate and Boundary Files',
                    'parser_name' => class_basename(static::class),
                    'raw_name' => sprintf('%s SST %.2f%% physical goods', $stateCode, $rate),
                    'parsed_name' => sprintf('%s %.2f%% SST Tax Region', $stateCode, $rate),
                    'tax_group_code' => 'PHYSICAL_TPP',
                    'tax_group_name' => $groupName,
                    'tax_group_description' => $groupDescription,
                    'jurisdiction_type' => 'sst_rate_boundary',
                    'jurisdiction_name' => sprintf('%s %.2f%% SST tax region', $stateCode, $rate),
                    'jurisdiction_code' => $region['code'],
                    'state_rate_percent' => round($region['state_rate_percent'], 4),
                    'local_rate_percent' => round($region['rate_percent'] - $region['state_rate_percent'], 4),
                    'rate_percent' => $rate,
                    'zip_code_hints' => $region['zip_ranges'],
                    'zip_code_ranges' => $region['zip_ranges'],
                    'boundary_source_url' => $boundaryFile,
                    'description' => sprintf(
                        'SST physical goods regional rate. State %.4f%% plus local %.4f%%. Boundary identifiers: %s. ZIP ranges: %s.',
                        $region['state_rate_percent'],
                        $region['rate_percent'] - $region['state_rate_percent'],
                        $region['code'],
                        $region['zip_ranges'] ?: 'not available'
                    ),
                    'info' => $region['truncated']
                        ? 'Coverage: SST parser stopped at the configured boundary row limit. Review official SST files before import.'
                        : 'Coverage: SST parser output from official rate and boundary files. ZIP5 ranges are still a convenience layer; use address/ZIP+4 validation for legal precision where required.',
                    'source_rows_count' => $region['rows_count'],
                    'source_hash' => hash('sha256', implode('|', [
                        $stateCode,
                        $region['code'],
                        $rate,
                        $region['zip_ranges'],
                        $rateFile,
                        $boundaryFile,
                    ])),
                ];
            })
            ->values()
            ->all();
    }

    protected function rates(string $file): array
    {
        $rates = [];

        foreach ($this->csvRows($file) as $row) {
            if (!$this->active($row[7] ?? null, $row[8] ?? null)) {
                continue;
            }

            $type = trim((string)($row[1] ?? ''));
            $code = trim((string)($row[2] ?? ''));

            if ($type === '' || $code === '') {
                continue;
            }

            $rates[$this->rateKey($type, $code)] = $this->percent($row[3] ?? 0);
        }

        return $rates;
    }

    protected function regions(string $file, array $rates, string $stateCode, float $stateRate, int $maxRows): array
    {
        $regions = [];
        $rows = 0;
        $truncated = false;

        foreach ($this->csvRows($file) as $row) {
            if (!$this->active($row[1] ?? null, $row[2] ?? null)) {
                continue;
            }

            $rows++;

            if ($maxRows > 0 && $rows > $maxRows) {
                $truncated = true;
                break;
            }

            $zipRanges = $this->zipRangesFor($row);

            if (!$zipRanges) {
                continue;
            }

            $county = $this->countyCode($row);
            $special = $this->specialDistrictCode($row);
            $localRate = ($county ? ($rates[$this->rateKey(self::RATE_TYPE_COUNTY, $county)] ?? 0.0) : 0.0)
                + ($special ? ($rates[$this->rateKey(self::RATE_TYPE_SPECIAL, $special)] ?? 0.0) : 0.0);
            $rate = round($stateRate + $localRate, 4);
            $code = implode('-', array_filter([
                $stateCode,
                $this->stateFipsFor($stateCode),
                $county ? 'C' . $county : null,
                $special ? 'S' . $special : null,
                str_replace('.', '', number_format($rate, 4, '.', '')),
            ]));

            $regions[$code] ??= [
                'code' => $code,
                'state_rate_percent' => $stateRate,
                'rate_percent' => $rate,
                'zips' => [],
                'rows_count' => 0,
                'truncated' => false,
            ];
            $regions[$code]['zips'] = array_merge($regions[$code]['zips'], $zipRanges);
            $regions[$code]['rows_count']++;
        }

        return collect($regions)
            ->map(function (array $region) use ($truncated) {
                $region['zip_ranges'] = $this->formatZipRanges($region['zips']);
                $region['truncated'] = $truncated;
                unset($region['zips']);

                return $region;
            })
            ->values()
            ->all();
    }

    protected function latestFileUrl(array $source, string $stateCode, string $kind, string $directory): ?string
    {
        if (app()->runningUnitTests()) {
            return null;
        }

        $base = sprintf('https://www.streamlinedsalestax.org/ratesandboundry/%s/', $directory);
        $html = $this->fetchSource($base);

        if (!$html) {
            return null;
        }

        preg_match_all('/href="([^"]*\/' . preg_quote($stateCode . $kind, '/') . '[^"]+\.(?:csv|CSV|zip))"/i', $html, $matches);
        $hrefs = collect($matches[1] ?? [])->unique()->values();

        if ($hrefs->isEmpty()) {
            return null;
        }

        $href = $hrefs->last();

        return str_starts_with($href, 'http') ? $href : 'https://www.streamlinedsalestax.org' . $href;
    }

    protected function csvRows(string $file): \Generator
    {
        $path = $this->localCsvPath($file);

        if (!$path) {
            return;
        }

        $handle = @fopen($path, 'rb');

        if (!$handle) {
            return;
        }

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if ($row !== [null]) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
            $this->cleanupTempPath($path, $file);
        }
    }

    protected function localCsvPath(string $file): ?string
    {
        if (!preg_match('/\.zip$/i', $file)) {
            return str_starts_with($file, 'http') ? $this->cachedRemoteFile($file) : $file;
        }

        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        $zipPath = $this->localZipPath($file);

        if (!$zipPath) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            $this->cleanupTempPath($zipPath, $file);

            return null;
        }

        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name && preg_match('/\.csv$/i', $name)) {
                $csvName = $name;
                break;
            }
        }

        if (!$csvName) {
            $zip->close();
            $this->cleanupTempPath($zipPath, $file);

            return null;
        }

        $csvPath = tempnam(sys_get_temp_dir(), 'posmall-sst-csv-');
        $input = $zip->getStream($csvName);
        $output = $csvPath ? @fopen($csvPath, 'wb') : false;

        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            $zip->close();
            $this->cleanupTempPath($zipPath, $file);

            return null;
        }

        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        $zip->close();
        $this->cleanupTempPath($zipPath, $file);

        return $csvPath ?: null;
    }

    protected function localZipPath(string $file): ?string
    {
        if (!str_starts_with($file, 'http')) {
            return $file;
        }

        return $this->cachedRemoteFile($file);
    }

    protected function cachedRemoteFile(string $url): ?string
    {
        $directory = storage_path('app/posmall/usa-tax-sources');

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $basename = basename(parse_url($url, PHP_URL_PATH) ?: hash('sha1', $url));
        $path = $directory . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $basename);

        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }

        $input = @fopen($url, 'rb');
        $output = $path ? @fopen($path, 'wb') : false;

        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            return null;
        }

        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        return $path;
    }

    protected function cleanupTempPath(string $path, string $original): void
    {
        if ($path !== $original && str_starts_with($path, sys_get_temp_dir()) && is_file($path)) {
            @unlink($path);
        }
    }

    protected function active(?string $begin, ?string $end): bool
    {
        $today = (int)date('Ymd');
        $begin = preg_match('/^\d{8}$/', (string)$begin) ? (int)$begin : 0;
        $end = preg_match('/^\d{8}$/', (string)$end) ? (int)$end : 99991231;

        return $today >= $begin && $today <= $end;
    }

    protected function zipRangesFor(array $row): array
    {
        $low = $this->zipAt($row, 17);
        $high = $this->zipAt($row, 19);

        if ($low && $high) {
            return [$low . '-' . $high];
        }

        $zip = $this->zipAt($row, 15) ?: $low;

        return $zip ? [$zip] : [];
    }

    protected function zipAt(array $row, int $index): ?string
    {
        $value = trim((string)($row[$index] ?? ''));

        return preg_match('/^\d{5}$/', $value) ? $value : null;
    }

    protected function countyCode(array $row): ?string
    {
        $value = trim((string)($row[24] ?? ''));

        return preg_match('/^\d{3}$/', $value) ? $value : null;
    }

    protected function specialDistrictCode(array $row): ?string
    {
        $value = trim((string)($row[25] ?? ''));

        return $value !== '' ? $value : null;
    }

    protected function rateKey(string $type, string $code): string
    {
        return trim($type) . ':' . trim($code);
    }

    protected function percent($value): float
    {
        $value = (float)$value;

        return abs($value) <= 1 ? $value * 100 : $value;
    }

    protected function stateFipsFor(string $stateCode): string
    {
        $map = [
            'AL' => '01', 'AK' => '02', 'AZ' => '04', 'AR' => '05', 'CA' => '06',
            'CO' => '08', 'CT' => '09', 'DE' => '10', 'DC' => '11', 'FL' => '12',
            'GA' => '13', 'HI' => '15', 'ID' => '16', 'IL' => '17', 'IN' => '18',
            'IA' => '19', 'KS' => '20', 'KY' => '21', 'LA' => '22', 'ME' => '23',
            'MD' => '24', 'MA' => '25', 'MI' => '26', 'MN' => '27', 'MS' => '28',
            'MO' => '29', 'MT' => '30', 'NE' => '31', 'NV' => '32', 'NH' => '33',
            'NJ' => '34', 'NM' => '35', 'NY' => '36', 'NC' => '37', 'ND' => '38',
            'OH' => '39', 'OK' => '40', 'OR' => '41', 'PA' => '42', 'RI' => '44',
            'SC' => '45', 'SD' => '46', 'TN' => '47', 'TX' => '48', 'UT' => '49',
            'VT' => '50', 'VA' => '51', 'WA' => '53', 'WV' => '54', 'WI' => '55',
            'WY' => '56',
        ];

        return $map[$stateCode] ?? '';
    }

    protected function formatZipRanges(array $ranges): ?string
    {
        $ranges = collect($ranges)
            ->map(fn ($range) => trim((string)$range))
            ->filter(fn ($range) => preg_match('/^\d{5}(?:-\d{5})?$/', $range))
            ->map(function (string $range) {
                if (!str_contains($range, '-')) {
                    return $range;
                }

                [$start, $end] = explode('-', $range, 2);

                return $start === $end ? $start : $range;
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ranges ? implode(', ', $ranges) : null;
    }
}
