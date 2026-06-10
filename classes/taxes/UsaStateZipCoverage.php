<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Carbon\Carbon;
use KodZero\POSMall\Models\Tax;

class UsaStateZipCoverage
{
    private const CENSUS_ZCTA_COUNTY_URL = 'https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt';

    private const COVERAGE_INFO = 'Coverage: Census ZCTA state fallback ranges. These ranges identify likely state ZIP coverage for checkout fallback; they are not local tax boundaries.';

    private const STATE_FIPS = [
        '01' => 'AL', '02' => 'AK', '04' => 'AZ', '05' => 'AR', '06' => 'CA',
        '08' => 'CO', '09' => 'CT', '10' => 'DE', '11' => 'DC', '12' => 'FL',
        '13' => 'GA', '15' => 'HI', '16' => 'ID', '17' => 'IL', '18' => 'IN',
        '19' => 'IA', '20' => 'KS', '21' => 'KY', '22' => 'LA', '23' => 'ME',
        '24' => 'MD', '25' => 'MA', '26' => 'MI', '27' => 'MN', '28' => 'MS',
        '29' => 'MO', '30' => 'MT', '31' => 'NE', '32' => 'NV', '33' => 'NH',
        '34' => 'NJ', '35' => 'NM', '36' => 'NY', '37' => 'NC', '38' => 'ND',
        '39' => 'OH', '40' => 'OK', '41' => 'OR', '42' => 'PA', '44' => 'RI',
        '45' => 'SC', '46' => 'SD', '47' => 'TN', '48' => 'TX', '49' => 'UT',
        '50' => 'VT', '51' => 'VA', '53' => 'WA', '54' => 'WV', '55' => 'WI',
        '56' => 'WY',
    ];

    public function syncAll(): array
    {
        $zipsByState = $this->zipsByState();
        $created = $this->ensureBaseTaxes($zipsByState);
        $taxes = $this->baseTaxes();
        $updated = 0;
        $unchanged = 0;

        foreach ($taxes as $tax) {
            $state = strtoupper((string)$tax->state_code);
            $zips = $zipsByState[$state] ?? [];

            if (!$zips) {
                continue;
            }

            $fallbackZips = $this->fallbackZipsFor($tax, $zips);
            $zipCodeHints = $this->formatZipHints($fallbackZips);
            $zipCodeRanges = $this->formatZipRanges($fallbackZips);

            if (
                $this->sameValue($tax->zip_code_hints, $zipCodeHints)
                && $this->sameValue($tax->zip_code_ranges, $zipCodeRanges)
                && $tax->boundary_source_url === self::CENSUS_ZCTA_COUNTY_URL
            ) {
                $unchanged++;
                continue;
            }

            $tax->zip_code_hints = $zipCodeHints;
            $tax->zip_code_ranges = $zipCodeRanges;
            $tax->boundary_source_url = self::CENSUS_ZCTA_COUNTY_URL;
            $tax->info = $this->withCoverageInfo((string)$tax->info);
            $tax->imported_at = Carbon::now();
            $tax->save();

            app(UsaTaxRegionRows::class)->syncForTax($tax->fresh('tax_group_code_rows') ?: $tax);
            $updated++;
        }

        $normalized = app(UsaZipRangeNormalizer::class)->normalizeAll();

        return [
            'states' => count($zipsByState),
            'taxes' => $taxes->count(),
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'normalized' => $normalized,
            'source' => self::CENSUS_ZCTA_COUNTY_URL,
        ];
    }

    protected function baseTaxes()
    {
        return Tax::with('tax_group_code_rows')
            ->whereNotNull('state_code')
            ->where(function ($query) {
                $query->whereNotNull('source_url')
                    ->orWhereNotNull('source_name')
                    ->orWhereNotNull('parser_name');
            })
            ->where(function ($query) {
                $query->whereNull('jurisdiction_code')->orWhere('jurisdiction_code', '');
            })
            ->where('is_enabled', true)
            ->orderBy('state_code')
            ->orderBy('id')
            ->get();
    }

    protected function ensureBaseTaxes(array $zipsByState): int
    {
        $created = 0;

        foreach (UsaTaxSourceRegistry::seedRules() as $rule) {
            [$state, $code, $rate, $sourceCode, $name] = $rule;
            $state = strtoupper((string)$state);
            $code = strtoupper((string)$code);

            if (empty($zipsByState[$state]) || $this->baseTaxExists($state, $code)) {
                continue;
            }

            $source = UsaTaxSourceRegistry::sourceByCode((string)$sourceCode);
            $group = UsaTaxSourceRegistry::taxGroups()[$code] ?? [$code, 'Review this tax group against the official state source before production use.'];
            [$groupName, $groupDescription] = $group;
            $mainGroup = Tax::taxMainGroupForCode($code);

            $tax = new Tax();
            $tax->fill([
                'name' => $name,
                'percentage' => (float)$rate,
                'rate_percent' => (float)$rate,
                'state_code' => $state,
                'state_codes' => [$state],
                'tax_group_code' => $code,
                'tax_group_name' => $groupName,
                'tax_group_description' => $groupDescription,
                'tax_main_group' => $mainGroup,
                'tax_main_group_name' => Tax::taxMainGroupOptions()[$mainGroup] ?? 'General',
                'jurisdiction_type' => 'state_zip_fallback',
                'jurisdiction_name' => $state . ' ZIP fallback',
                'jurisdiction_code' => null,
                'state_rate_percent' => (float)$rate,
                'local_rate_percent' => 0,
                'description' => 'Statewide ZIP fallback generated from the configured official source rate and Census ZCTA state coverage. More precise jurisdiction rows override these ZIP ranges.',
                'source_url' => $source['url'] ?? null,
                'source_type' => $source['type'] ?? 'CENSUS_ZCTA_FALLBACK',
                'source_name' => $source['name'] ?? 'Census ZCTA state fallback',
                'parser_name' => class_basename(static::class),
                'is_enabled' => true,
                'is_active' => true,
                'imported_at' => Carbon::now(),
            ]);
            $tax->save();
            $tax->tax_group_code_rows()->updateOrCreate(
                ['tax_group_code' => $code],
                [
                    'tax_group_name' => $groupName,
                    'tax_group_description' => $groupDescription,
                ]
            );

            $created++;
        }

        return $created;
    }

    protected function baseTaxExists(string $state, string $taxGroupCode): bool
    {
        return Tax::where('state_code', $state)
            ->where(function ($query) {
                $query->whereNull('jurisdiction_code')->orWhere('jurisdiction_code', '');
            })
            ->where(function ($query) use ($taxGroupCode) {
                $query->where('tax_group_code', $taxGroupCode)
                    ->orWhereHas('tax_group_code_rows', function ($query) use ($taxGroupCode) {
                        $query->where('tax_group_code', $taxGroupCode);
                    });
            })
            ->where('is_enabled', true)
            ->exists();
    }

    protected function fallbackZipsFor(Tax $tax, array $stateZips): array
    {
        $preciseZips = $this->preciseZipsFor($tax);

        if (!$preciseZips) {
            return $stateZips;
        }

        return collect($stateZips)
            ->reject(fn (string $zip) => isset($preciseZips[$zip]))
            ->values()
            ->all();
    }

    protected function preciseZipsFor(Tax $tax): array
    {
        $state = strtoupper((string)$tax->state_code);
        $mainGroup = (string)$tax->tax_main_group;
        $taxGroupCodes = $tax->taxGroupCodesList();

        if (!$state || !$mainGroup) {
            return [];
        }

        $ranges = Tax::where('is_enabled', true)
            ->where('state_code', $state)
            ->where('tax_main_group', $mainGroup)
            ->where('id', '!=', $tax->id)
            ->whereNotNull('zip_code_ranges')
            ->where(function ($query) {
                $query->whereNotNull('jurisdiction_code')->where('jurisdiction_code', '!=', '');
            });

        if ($taxGroupCodes) {
            $ranges->where(function ($query) use ($taxGroupCodes) {
                $query->whereIn('tax_group_code', $taxGroupCodes)
                    ->orWhereHas('tax_group_code_rows', function ($query) use ($taxGroupCodes) {
                        $query->whereIn('tax_group_code', $taxGroupCodes);
                    });
            });
        }

        $ranges = $ranges
            ->pluck('zip_code_ranges');

        return collect($ranges)
            ->flatMap(fn ($ranges) => $this->expandZipRanges((string)$ranges))
            ->unique()
            ->mapWithKeys(fn (string $zip) => [$zip => true])
            ->all();
    }

    protected function zipsByState(): array
    {
        $path = $this->localCensusFile();
        $handle = @fopen($path, 'rb');

        if (!$handle) {
            return [];
        }

        $zips = [];
        fgetcsv($handle, 0, '|');

        try {
            while (($row = fgetcsv($handle, 0, '|')) !== false) {
                $zip = trim((string)($row[1] ?? ''));
                $county = trim((string)($row[9] ?? ''));

                if (!preg_match('/^\d{5}$/', $zip) || !preg_match('/^\d{5}$/', $county)) {
                    continue;
                }

                $state = self::STATE_FIPS[substr($county, 0, 2)] ?? null;

                if ($state) {
                    $zips[$state][$zip] = $zip;
                }
            }
        } finally {
            fclose($handle);
        }

        ksort($zips);

        return collect($zips)
            ->map(fn (array $stateZips) => collect($stateZips)->sort()->values()->all())
            ->all();
    }

    protected function localCensusFile(): string
    {
        $directory = storage_path('app/posmall/usa-tax-sources');

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $path = $directory . '/tab20_zcta520_county20_natl.txt';

        if (is_file($path) && filesize($path) > 0) {
            return $path;
        }

        $input = @fopen(self::CENSUS_ZCTA_COUNTY_URL, 'rb');
        $output = @fopen($path, 'wb');

        if (!$input || !$output) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }

            return $path;
        }

        stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        return $path;
    }

    protected function expandZipRanges(string $ranges): array
    {
        return collect(preg_split('/\s*,\s*/', $ranges) ?: [])
            ->flatMap(function (string $range) {
                $range = trim($range);

                if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $range, $matches)) {
                    $from = (int)min($matches[1], $matches[2]);
                    $to = (int)max($matches[1], $matches[2]);

                    return collect(range($from, $to))
                        ->map(fn (int $zip) => str_pad((string)$zip, 5, '0', STR_PAD_LEFT));
                }

                return preg_match('/^\d{5}$/', $range) ? [$range] : [];
            })
            ->values()
            ->all();
    }

    protected function formatZipHints(array $zips): ?string
    {
        if (!$zips) {
            return null;
        }

        return implode(', ', array_slice($zips, 0, 80)) . (count($zips) > 80 ? ', ...' : '');
    }

    protected function formatZipRanges(array $zips): ?string
    {
        if (!$zips) {
            return null;
        }

        $numbers = collect($zips)
            ->map(fn ($zip) => (int)$zip)
            ->sort()
            ->values()
            ->all();

        $ranges = [];
        $start = $numbers[0] ?? null;
        $previous = $start;

        foreach (array_slice($numbers, 1) as $zip) {
            if ($zip === $previous + 1) {
                $previous = $zip;
                continue;
            }

            $ranges[] = $this->formatRange($start, $previous);
            $start = $previous = $zip;
        }

        if ($start !== null) {
            $ranges[] = $this->formatRange($start, $previous);
        }

        return implode(', ', $ranges);
    }

    protected function sameValue($left, $right): bool
    {
        return trim((string)$left) === trim((string)$right);
    }

    protected function withCoverageInfo(string $info): string
    {
        $info = trim($info);

        if (strpos($info, self::COVERAGE_INFO) !== false) {
            return $info;
        }

        return trim($info . "\n" . self::COVERAGE_INFO);
    }

    protected function formatRange(int $start, int $end): string
    {
        $start = str_pad((string)$start, 5, '0', STR_PAD_LEFT);
        $end = str_pad((string)$end, 5, '0', STR_PAD_LEFT);

        return $start === $end ? $start : $start . '-' . $end;
    }
}
