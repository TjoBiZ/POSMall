<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;

class WashingtonLocalRateTableParser extends SeedRuleSourceParser
{
    public function parse(array $source, string $stateCode): array
    {
        $rows = app()->runningUnitTests()
            ? []
            : $this->rowsFromOfficialTable($source);
        $zipHints = app()->runningUnitTests() || !$this->shouldScanBoundaryFile($source)
            ? []
            : $this->zipHintsFromBoundaryFile($source);
        $zipHints = collect($this->fallbackZipHints($source))
            ->merge($zipHints)
            ->all();
        $zipHintsByRate = app()->runningUnitTests() || !$this->shouldScanBoundaryFile($source)
            ? []
            : $this->zipHintsByCombinedRate($source);

        $rows = collect($source['local_rate_examples'] ?? [])
            ->merge($rows)
            ->unique(fn ($row) => (string)($row['code'] ?? '') ?: (string)($row['name'] ?? ''))
            ->values()
            ->all();

        return collect($rows)
            ->filter(fn ($row) => isset($row['name'], $row['code'], $row['combined_rate']))
            ->flatMap(fn ($row) => $this->recordsForTaxGroups($source, $row, $zipHints))
            ->map(function ($record) use ($zipHintsByRate) {
                $rateKey = $this->rateKey((float)$record['rate_percent']);
                $zips = collect($this->zipListFromHints($record['zip_code_hints'] ?? null))
                    ->merge($zipHintsByRate[$rateKey] ?? [])
                    ->unique()
                    ->values()
                    ->all();

                $record['zip_code_hints'] = $this->formatZipHints($zips);
                $record['zip_code_ranges'] = $this->formatZipRanges($zips);

                return $record;
            })
            ->values()
            ->all();
    }

    protected function recordsForTaxGroups(array $source, array $row, array $zipHints = []): array
    {
        return collect($source['tax_group_codes'] ?? ['PHYSICAL_TPP'])
            ->map(fn ($groupCode) => $this->record($source, $row, $zipHints, (string)$groupCode))
            ->all();
    }

    protected function rowsFromOfficialTable(array $source): array
    {
        $url = (string)($source['url'] ?? '');
        if (!$url) {
            return [];
        }

        $rows = [];
        $maxPages = (int)($source['max_pages'] ?? 8);

        for ($page = 0; $page < $maxPages; $page++) {
            $pageRows = $this->rowsFromHtml($this->fetchSource($this->pageUrl($url, $page)) ?: '');

            if (!$pageRows) {
                break;
            }

            $rows = array_merge($rows, $pageRows);
        }

        return $rows;
    }

    protected function rowsFromHtml(string $html): array
    {
        if (!$html) {
            return [];
        }

        $rows = [];
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        foreach ($document->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }

            if (count($cells) < 7) {
                continue;
            }

            $rows[] = [
                'county' => $cells[1],
                'name' => $cells[2],
                'code' => $cells[3],
                'local_rate' => $this->percentFromDecimal($cells[4]),
                'state_rate' => $this->percentFromDecimal($cells[5]),
                'combined_rate' => $this->percentFromDecimal($cells[6]),
            ];
        }

        return $rows;
    }

    protected function pageUrl(string $url, int $page): string
    {
        if (preg_match('/([?&])page=\\d+/', $url)) {
            return preg_replace('/([?&])page=\\d+/', '$1page=' . $page, $url);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'page=' . $page;
    }

    protected function record(array $source, array $row, array $zipHints = [], string $groupCode = 'PHYSICAL_TPP'): array
    {
        [$groupName, $groupDescription] = UsaTaxSourceRegistry::taxGroups()[$groupCode] ?? [$groupCode, 'Review this Washington taxable group against official DOR guidance.'];
        $areaName = trim(implode(' / ', array_filter([
            !empty($row['county']) ? $row['county'] . ' County' : null,
            $row['name'],
        ])));
        $name = sprintf('Washington Sales Tax - %s %s', $areaName ?: $row['name'], $groupName);
        $zipHintsForCode = $zipHints[(string)$row['code']] ?? ($row['zip_code_hints'] ?? []);
        $payload = implode('|', [
            'WA',
            $groupCode,
            $row['code'],
            $row['combined_rate'],
            implode(',', $zipHintsForCode),
            $source['url'] ?? '',
        ]);

        return [
            'state_code' => 'WA',
            'source_url' => $source['url'] ?? null,
            'source_type' => $source['type'] ?? 'HTML',
            'source_name' => $source['name'] ?? 'Washington DOR Local Rate Table',
            'parser_name' => class_basename(static::class),
            'raw_name' => $name,
            'parsed_name' => $name,
            'tax_group_code' => $groupCode,
            'tax_group_name' => $groupName,
            'tax_group_description' => $groupDescription,
            'jurisdiction_type' => 'city_or_local_area',
            'jurisdiction_name' => $areaName ?: $row['name'],
            'jurisdiction_code' => (string)$row['code'],
            'state_rate_percent' => (float)($row['state_rate'] ?? 6.5),
            'local_rate_percent' => (float)($row['local_rate'] ?? 0),
            'rate_percent' => (float)$row['combined_rate'],
            'zip_code_hints' => $this->formatZipHints($zipHintsForCode),
            'zip_code_ranges' => $this->formatZipRanges($zipHintsForCode),
            'boundary_source_url' => $source['boundary_url'] ?? null,
            'description' => sprintf(
                'Washington local destination rate for %s%s. ZIP hints are informational only; use address or ZIP+4 boundary lookup for exact sales.',
                $row['name'],
                !empty($row['county']) ? ' in ' . $row['county'] . ' County' : ''
            ),
            'source_hash' => hash('sha256', $payload),
        ];
    }

    protected function percentFromDecimal(string $value): float
    {
        $value = trim($value);

        if ($value === '') {
            return 0.0;
        }

        return round((float)$value * 100, 4);
    }

    protected function zipHintsFromBoundaryFile(array $source): array
    {
        $url = (string)($source['boundary_url'] ?? '');
        if (!$url || !class_exists(\ZipArchive::class)) {
            return $this->fallbackZipHints($source);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'wa-boundary-');
        $contents = $this->fetchBinary($url);
        if (!$contents) {
            return $this->fallbackZipHints($source);
        }

        file_put_contents($zipPath, $contents);

        $archive = new \ZipArchive();
        if ($archive->open($zipPath) !== true || $archive->numFiles < 1) {
            @unlink($zipPath);

            return $this->fallbackZipHints($source);
        }

        $stream = $archive->getStream($archive->getNameIndex(0));
        if (!$stream) {
            $archive->close();
            @unlink($zipPath);

            return $this->fallbackZipHints($source);
        }

        $hints = [];
        $maxRows = (int)($source['boundary_max_rows'] ?? 400000);
        $maxZipsPerCode = (int)($source['max_zip_hints_per_code'] ?? 20);
        $rows = 0;

        while (($line = fgets($stream)) !== false && ++$rows <= $maxRows) {
            $columns = str_getcsv($line);
            $zips = $this->zipsFromBoundaryColumns($columns);
            $codes = $this->locationCodesFromBoundaryColumns($columns);

            if (!$zips || !$codes) {
                continue;
            }

            foreach ($codes as $code) {
                $hints[$code] ??= [];

                foreach ($zips as $zip) {
                    if (count($hints[$code]) >= $maxZipsPerCode) {
                        break;
                    }

                    $hints[$code][$zip] = $zip;
                }
            }
        }

        fclose($stream);
        $archive->close();
        @unlink($zipPath);

        return collect($hints)->map(fn ($zips) => array_values($zips))->all();
    }

    protected function shouldScanBoundaryFile(array $source): bool
    {
        return (bool)($source['scan_boundary_file'] ?? false);
    }

    protected function fetchBinary(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 25,
                'user_agent' => 'POSMall USA Tax Helper',
            ],
        ]);

        $contents = @file_get_contents($url, false, $context);

        return is_string($contents) ? $contents : null;
    }

    protected function fallbackZipHints(array $source): array
    {
        return collect($source['local_rate_examples'] ?? [])
            ->filter(fn ($row) => !empty($row['code']) && !empty($row['zip_code_hints']))
            ->mapWithKeys(fn ($row) => [(string)$row['code'] => (array)$row['zip_code_hints']])
            ->all();
    }

    protected function zipsFromBoundaryColumns(array $columns): array
    {
        return collect([
                $columns[17] ?? null,
                $columns[19] ?? null,
            ])
            ->map(fn ($column) => trim((string)$column))
            ->filter(fn ($column) => preg_match('/^\d{5}$/', $column))
            ->unique()
            ->values()
            ->all();
    }

    protected function locationCodesFromBoundaryColumns(array $columns): array
    {
        return collect($columns)
            ->map(fn ($column) => trim((string)$column))
            ->map(function ($column) {
                if (preg_match('/^L(\d{4})$/', $column, $matches)) {
                    return $matches[1];
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function zipHintsByCombinedRate(array $source): array
    {
        $rateUrl = (string)($source['rate_url'] ?? '');
        $boundaryUrl = (string)($source['boundary_url'] ?? '');

        if (!$rateUrl || !$boundaryUrl || !class_exists(\ZipArchive::class)) {
            return [];
        }

        $rates = $this->ratesFromSstRateFile($rateUrl);
        if (!$rates['state'] || !$rates['local']) {
            return [];
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'wa-boundary-rate-');
        $contents = $this->fetchBinary($boundaryUrl);
        if (!$contents) {
            return [];
        }

        file_put_contents($zipPath, $contents);

        $archive = new \ZipArchive();
        if ($archive->open($zipPath) !== true || $archive->numFiles < 1) {
            @unlink($zipPath);

            return [];
        }

        $stream = $archive->getStream($archive->getNameIndex(0));
        if (!$stream) {
            $archive->close();
            @unlink($zipPath);

            return [];
        }

        $hints = [];
        $maxRows = (int)($source['boundary_max_rows'] ?? 400000);
        $rows = 0;

        while (($line = fgets($stream)) !== false && ++$rows <= $maxRows) {
            $columns = str_getcsv($line);
            $zips = $this->zipsFromBoundaryColumns($columns);
            $localCode = $this->localRateCodeFromBoundaryColumns($columns);

            if (!$zips || !$localCode || !isset($rates['local'][$localCode])) {
                continue;
            }

            $rateKey = $this->rateKey(($rates['state'] + $rates['local'][$localCode]) * 100);
            $hints[$rateKey] ??= [];

            foreach ($zips as $zip) {
                $hints[$rateKey][$zip] = $zip;
            }
        }

        fclose($stream);
        $archive->close();
        @unlink($zipPath);

        return collect($hints)->map(fn ($zips) => array_values($zips))->all();
    }

    protected function ratesFromSstRateFile(string $url): array
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'wa-rate-');
        $contents = $this->fetchBinary($url);
        if (!$contents) {
            return ['state' => null, 'local' => []];
        }

        file_put_contents($zipPath, $contents);

        $archive = new \ZipArchive();
        if ($archive->open($zipPath) !== true || $archive->numFiles < 1) {
            @unlink($zipPath);

            return ['state' => null, 'local' => []];
        }

        $stream = $archive->getStream($archive->getNameIndex(0));
        if (!$stream) {
            $archive->close();
            @unlink($zipPath);

            return ['state' => null, 'local' => []];
        }

        $stateRate = null;
        $localRates = [];

        while (($line = fgets($stream)) !== false) {
            $columns = str_getcsv($line);
            $type = $columns[1] ?? null;
            $code = $columns[2] ?? null;
            $rate = isset($columns[3]) ? (float)$columns[3] : null;

            if ($type === '45' && $rate !== null) {
                $stateRate = $rate;
            }

            if ($type === '00' && $code && $rate !== null) {
                $localRates[$code] = $rate;
            }
        }

        fclose($stream);
        $archive->close();
        @unlink($zipPath);

        return ['state' => $stateRate, 'local' => $localRates];
    }

    protected function localRateCodeFromBoundaryColumns(array $columns): ?string
    {
        $code = trim((string)($columns[24] ?? ''));

        return preg_match('/^\d{3}$/', $code) ? $code : null;
    }

    protected function rateKey(float $rate): string
    {
        return number_format(round($rate, 2), 2, '.', '');
    }

    protected function formatZipHints(array $zips): ?string
    {
        $zips = collect($zips)
            ->map(fn ($zip) => trim((string)$zip))
            ->filter(fn ($zip) => preg_match('/^\d{5}$/', $zip))
            ->unique()
            ->values()
            ->all();

        return $zips ? implode(', ', $zips) : null;
    }

    protected function formatZipRanges(array $zips): ?string
    {
        $zips = collect($zips)
            ->map(fn ($zip) => trim((string)$zip))
            ->filter(fn ($zip) => preg_match('/^\d{5}$/', $zip))
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($zip) => (int)$zip)
            ->all();

        if (!$zips) {
            return null;
        }

        $ranges = [];
        $start = $zips[0];
        $prev = $zips[0];

        foreach (array_slice($zips, 1) as $zip) {
            if ($zip === $prev + 1) {
                $prev = $zip;
                continue;
            }

            $ranges[] = $this->formatRange($start, $prev);
            $start = $prev = $zip;
        }

        $ranges[] = $this->formatRange($start, $prev);

        return implode(', ', $ranges);
    }

    protected function formatRange(int $start, int $end): string
    {
        $start = str_pad((string)$start, 5, '0', STR_PAD_LEFT);
        $end = str_pad((string)$end, 5, '0', STR_PAD_LEFT);

        return $start === $end ? $start : $start . '-' . $end;
    }

    protected function zipListFromHints(?string $hints): array
    {
        if (!$hints) {
            return [];
        }

        preg_match_all('/\b\d{5}\b/', $hints, $matches);

        return $matches[0] ?? [];
    }
}
