<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

class GeographicTaxRateGrouper
{
    public function group(array $records): array
    {
        return collect($records)
            ->groupBy(fn ($record) => $this->groupKey($record))
            ->map(function ($group) {
                $rows = $group->values()->all();

                if (count($rows) === 1 && !$this->hasGeographicData($rows[0])) {
                    return $rows[0];
                }

                return $this->groupRecord($rows);
            })
            ->values()
            ->all();
    }

    protected function groupKey(array $record): string
    {
        return implode('|', [
            $record['state_code'] ?? '',
            $record['tax_group_code'] ?? '',
            number_format((float)($record['state_rate_percent'] ?? 0), 4, '.', ''),
            number_format((float)($record['local_rate_percent'] ?? 0), 4, '.', ''),
            number_format((float)($record['rate_percent'] ?? 0), 4, '.', ''),
            $record['source_url'] ?? '',
            $record['parser_name'] ?? '',
        ]);
    }

    protected function groupRecord(array $records): array
    {
        $first = $records[0];
        $state = strtoupper((string)($first['state_code'] ?? 'US'));
        $rate = (float)($first['rate_percent'] ?? 0);
        $areas = $this->areaNames($records);
        $codes = $this->jurisdictionCodes($records);
        $zips = collect($records)
            ->flatMap(fn ($record) => $this->zipList($record['zip_code_hints'] ?? null))
            ->merge(collect($records)->flatMap(fn ($record) => $this->zipList($record['zip_code_ranges'] ?? null)))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $zipRanges = $this->formatZipRanges($zips);
        $codeHash = substr(hash('sha1', implode('|', $codes) . '|' . implode('|', $areas)), 0, 10);

        $first['raw_name'] = sprintf('%s %.2f%% Tax Region Group', $state, $rate);
        $first['parsed_name'] = $first['raw_name'];
        $first['jurisdiction_type'] = 'geographic_tax_rate_group';
        $first['jurisdiction_name'] = sprintf('%s %.2f%% tax region group', $state, $rate);
        $first['jurisdiction_code'] = sprintf('%s-%s-%s', $state, str_replace('.', '', number_format($rate, 2, '.', '')), $codeHash);
        $first['zip_code_hints'] = $this->formatZipHints($zips);
        $first['zip_code_ranges'] = $zipRanges;
        $first['description'] = $this->description($first, $areas, $codes, $zipRanges);
        $first['info'] = $this->coverageInfo($state, $rate, $zipRanges);
        $first['source_hash'] = hash('sha256', implode('|', [
            $first['state_code'] ?? '',
            $first['tax_group_code'] ?? '',
            $first['jurisdiction_code'] ?? '',
            $first['rate_percent'] ?? '',
            $first['zip_code_ranges'] ?? '',
            $first['source_url'] ?? '',
        ]));

        return $first;
    }

    protected function coverageInfo(string $state, float $rate, ?string $zipRanges): string
    {
        if ($zipRanges) {
            return sprintf(
                'Coverage: grouped local %s %.2f%% tax record with ZIP hints/ranges from parser output. ZIPs remain hints; use official address or ZIP+4 lookup for legal precision where required.',
                $state,
                $rate
            );
        }

        return sprintf(
            'Coverage: grouped local %s %.2f%% tax record without verified lightweight ZIP ranges. Checkout will not select this local row by ZIP until ranges are available; use the statewide/base row or official address/ZIP+4 lookup.',
            $state,
            $rate
        );
    }

    protected function description(array $record, array $areas, array $codes, ?string $zipRanges): string
    {
        $rate = (float)($record['rate_percent'] ?? 0);
        $stateRate = $record['state_rate_percent'] ?? null;
        $localRate = $record['local_rate_percent'] ?? null;
        $components = ($stateRate !== null || $localRate !== null)
            ? sprintf(' Rate components: State %.2f%% + Local %.2f%%.', (float)$stateRate, (float)$localRate)
            : '';

        return sprintf(
            'Product tax group: %s (%s). Geographic tax rate group: %s / %.2f%%.%s Counties/areas/regions: %s. Source row codes: %s. ZIP ranges: %s.',
            $record['tax_group_code'] ?? 'UNKNOWN',
            $record['tax_group_name'] ?? 'Review product tax group',
            $record['state_code'] ?? 'US',
            $rate,
            $components,
            $areas ? implode('; ', $areas) : 'Statewide/base or not listed by source',
            $codes ? implode(', ', $codes) : 'Not listed',
            $zipRanges ?: 'Not available from this source'
        );
    }

    protected function hasGeographicData(array $record): bool
    {
        if (
            ($record['jurisdiction_type'] ?? null) === 'statewide_taxability'
            && empty($record['jurisdiction_code'])
            && empty($record['zip_code_hints'])
            && empty($record['zip_code_ranges'])
        ) {
            return false;
        }

        return (bool)(
            ($record['jurisdiction_code'] ?? null)
            || ($record['zip_code_hints'] ?? null)
            || ($record['zip_code_ranges'] ?? null)
        );
    }

    protected function areaNames(array $records): array
    {
        return collect($records)
            ->map(fn ($record) => trim((string)($record['jurisdiction_name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function jurisdictionCodes(array $records): array
    {
        return collect($records)
            ->map(fn ($record) => trim((string)($record['jurisdiction_code'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function zipList(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $zips = [];
        foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
            if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $part, $matches)) {
                $start = (int)$matches[1];
                $end = (int)$matches[2];

                for ($zip = min($start, $end); $zip <= max($start, $end); $zip++) {
                    $zips[] = str_pad((string)$zip, 5, '0', STR_PAD_LEFT);
                }

                continue;
            }

            if (preg_match('/^\d{5}$/', $part)) {
                $zips[] = $part;
            }
        }

        return $zips;
    }

    protected function formatZipHints(array $zips): ?string
    {
        $zips = $this->cleanZips($zips);

        return $zips ? implode(', ', $zips) : null;
    }

    protected function formatZipRanges(array $zips): ?string
    {
        $zips = collect($this->cleanZips($zips))
            ->map(fn ($zip) => (int)$zip)
            ->values()
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

    protected function cleanZips(array $zips): array
    {
        return collect($zips)
            ->map(fn ($zip) => trim((string)$zip))
            ->filter(fn ($zip) => preg_match('/^\d{5}$/', $zip))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function formatRange(int $start, int $end): string
    {
        $start = str_pad((string)$start, 5, '0', STR_PAD_LEFT);
        $end = str_pad((string)$end, 5, '0', STR_PAD_LEFT);

        return $start === $end ? $start : $start . '-' . $end;
    }
}
