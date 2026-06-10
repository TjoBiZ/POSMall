<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

class WashingtonDorZip4Parser extends WashingtonLocalRateTableParser
{
    public function parse(array $source, string $stateCode): array
    {
        if (app()->runningUnitTests()) {
            return [];
        }

        $rows = $this->rowsFromZip4File($source, $this->ratesFromDorFile($source));

        return collect($rows)
            ->flatMap(fn ($row) => $this->recordsForTaxGroups($source, $row, [
                (string)$row['code'] => $row['zip_code_hints'] ?? [],
            ]))
            ->values()
            ->all();
    }

    protected function rowsFromZip4File(array $source, array $rates): array
    {
        $zipOptions = [];

        $this->eachZipLine((string)($source['zip4_url'] ?? ''), function (string $line) use (&$zipOptions, $rates) {
            $columns = str_getcsv($line);

            if (count($columns) < 8 || !$this->activeDateRange($columns[7] ?? null, $columns[8] ?? null)) {
                return;
            }

            $zip = trim((string)($columns[0] ?? ''));
            $code = trim((string)($columns[3] ?? ''));

            if (!preg_match('/^\d{5}$/', $zip) || !preg_match('/^\d{4}$/', $code)) {
                return;
            }

            $rate = $rates[$code] ?? [];
            $stateRate = $this->ratePercent($rate['state_rate'] ?? ($columns[4] ?? 0));
            $localRate = $this->ratePercent($rate['local_rate'] ?? ($columns[5] ?? 0));
            $combinedRate = $this->ratePercent($rate['combined_rate'] ?? ($columns[6] ?? 0));
            $key = $code . '|' . number_format($combinedRate, 4, '.', '');
            $weight = $this->plusFourWeight($columns[1] ?? null, $columns[2] ?? null);
            $effectiveStart = $this->dateValue($columns[7] ?? null);
            $effectiveEnd = $this->dateValue($columns[8] ?? null) ?: 99991231;

            $zipOptions[$zip][$key] ??= [
                'weight' => 0,
                'name' => $rate['name'] ?? 'Location code ' . $code,
                'code' => $code,
                'local_rate' => $localRate,
                'state_rate' => $stateRate,
                'combined_rate' => $combinedRate,
                'effective_start' => $effectiveStart,
                'effective_end' => $effectiveEnd,
            ];

            $zipOptions[$zip][$key]['weight'] += $weight;
            $zipOptions[$zip][$key]['effective_start'] = max($zipOptions[$zip][$key]['effective_start'], $effectiveStart);
            $zipOptions[$zip][$key]['effective_end'] = min($zipOptions[$zip][$key]['effective_end'], $effectiveEnd);
        });

        $rows = [];

        foreach ($zipOptions as $zip => $options) {
            $selected = $this->dominantZipOption($options);
            $code = $selected['code'];

            $rows[$code] ??= [
                'county' => null,
                'name' => $selected['name'],
                'code' => $code,
                'local_rate' => $selected['local_rate'],
                'state_rate' => $selected['state_rate'],
                'combined_rate' => $selected['combined_rate'],
                'effective_start' => $selected['effective_start'],
                'effective_end' => $selected['effective_end'],
                'zip_code_hints' => [],
                'zip4_conflict_count' => 0,
            ];

            $rows[$code]['zip_code_hints'][$zip] = $zip;

            if (count($options) > 1) {
                $rows[$code]['zip4_conflict_count']++;
            }
        }

        return collect($rows)
            ->map(function (array $row) {
                $row['zip_code_hints'] = array_values($row['zip_code_hints']);

                return $row;
            })
            ->values()
            ->all();
    }

    protected function ratesFromDorFile(array $source): array
    {
        $rates = [];
        $header = true;

        $this->eachZipLine((string)($source['rate_url'] ?? ''), function (string $line) use (&$rates, &$header) {
            $columns = str_getcsv($line);

            if ($header) {
                $header = false;
                return;
            }

            $code = trim((string)($columns[1] ?? ''));

            if (!preg_match('/^\d{4}$/', $code)) {
                return;
            }

            $rates[$code] = [
                'name' => ucwords(strtolower(trim((string)($columns[0] ?? 'Location code ' . $code)))),
                'state_rate' => $columns[2] ?? 0,
                'local_rate' => $columns[3] ?? 0,
                'combined_rate' => $columns[5] ?? 0,
            ];
        });

        return $rates;
    }

    protected function record(array $source, array $row, array $zipHints = [], string $groupCode = 'PHYSICAL_TPP'): array
    {
        $record = parent::record($source, $row, $zipHints, $groupCode);
        $record['boundary_source_url'] = $source['zip4_url'] ?? null;
        $record['effective_from'] = $this->dateString($row['effective_start'] ?? null);
        $record['effective_to'] = $this->dateString($row['effective_end'] ?? null);
        $conflicts = (int)($row['zip4_conflict_count'] ?? 0);
        $record['description'] .= ' Built from the DOR ZIP+4 short database. The stored 5-digit ZIP ranges are checkout hints; use Washington DOR address or ZIP+4 lookup for exact boundary-level tax decisions.';
        if ($conflicts > 0) {
            $record['description'] .= sprintf(' %d ZIP code(s) in this group had multiple ZIP+4 rate candidates and were assigned to the dominant ZIP+4 coverage candidate.', $conflicts);
        }
        $record['info'] = 'Coverage: Washington DOR ZIP+4 downloadable database mapped to compressed 5-digit ZIP ranges for POSMall lookup. Exact Washington sourcing remains address/ZIP+4 based.';
        $record['source_hash'] = hash('sha256', implode('|', [
            $record['state_code'],
            $record['tax_group_code'],
            $record['jurisdiction_code'],
            $record['rate_percent'],
            $record['zip_code_ranges'],
            $source['zip4_url'] ?? '',
            $source['rate_url'] ?? '',
        ]));

        return $record;
    }

    protected function dominantZipOption(array $options): array
    {
        return collect($options)
            ->sort(function (array $left, array $right) {
                foreach (['weight', 'effective_start'] as $key) {
                    if ($left[$key] === $right[$key]) {
                        continue;
                    }

                    return $left[$key] < $right[$key] ? 1 : -1;
                }

                return strcmp((string)$left['code'], (string)$right['code']);
            })
            ->first();
    }

    protected function plusFourWeight($from, $to): int
    {
        $from = preg_match('/^\d{4}$/', (string)$from) ? (int)$from : 0;
        $to = preg_match('/^\d{4}$/', (string)$to) ? (int)$to : $from;

        return max(1, $to - $from + 1);
    }

    protected function eachZipLine(string $url, callable $callback): void
    {
        if (!$url || !class_exists(\ZipArchive::class)) {
            return;
        }

        $path = $this->cachedFile($url);
        $archive = new \ZipArchive();

        if (!$path || $archive->open($path) !== true || $archive->numFiles < 1) {
            return;
        }

        $stream = $archive->getStream($archive->getNameIndex(0));

        if (!$stream) {
            $archive->close();

            return;
        }

        while (($line = fgets($stream)) !== false) {
            $callback($line);
        }

        fclose($stream);
        $archive->close();
    }

    protected function cachedFile(string $url): ?string
    {
        $directory = storage_path('app/posmall/usa-tax-sources');

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: hash('sha1', $url));
        $path = $directory . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name);

        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }

        $contents = $this->fetchBinary($url);
        if (!$contents) {
            return null;
        }

        file_put_contents($path, $contents);

        return $path;
    }

    protected function ratePercent($value): float
    {
        return round((float)$value * 100, 4);
    }

    protected function activeDateRange($from, $to): bool
    {
        $today = (int)date('Ymd');
        $from = $this->dateValue($from);
        $to = $this->dateValue($to) ?: 99991231;

        return $today >= $from && $today <= $to;
    }

    protected function dateValue($value): int
    {
        return preg_match('/^\d{8}$/', (string)$value) ? (int)$value : 0;
    }

    protected function dateString($value): ?string
    {
        $value = $this->dateValue($value);

        if (!$value || $value === 99991231) {
            return null;
        }

        return substr((string)$value, 0, 4) . '-' . substr((string)$value, 4, 2) . '-' . substr((string)$value, 6, 2);
    }
}
