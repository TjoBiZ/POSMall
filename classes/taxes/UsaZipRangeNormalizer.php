<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Carbon\Carbon;
use KodZero\POSMall\Models\Tax;

class UsaZipRangeNormalizer
{
    private const INFO = 'Normalization: ZIP5 fallback ranges were de-overlapped by source priority, then by conservative highest-rate fallback where a ZIP5 has conflicting rates.';

    public function normalizeAll(): array
    {
        $taxes = Tax::with('tax_group_code_rows')
            ->where('is_enabled', true)
            ->whereNotNull('state_code')
            ->whereNotNull('zip_code_ranges')
            ->orderBy('state_code')
            ->orderBy('id')
            ->get();

        $candidates = $this->candidates($taxes);
        $winners = [];
        $conflicts = 0;

        foreach ($candidates as $key => $rows) {
            if (count($rows) > 1) {
                $rates = collect($rows)->pluck('rate')->unique();
                $conflicts += $rates->count() > 1 ? 1 : 0;
            }

            $winner = collect($rows)
                ->sortByDesc(fn (array $row) => [
                    $row['priority'],
                    $row['rate'],
                    $row['effective_from'],
                    -$row['tax_id'],
                ])
                ->first();

            if ($winner) {
                $winners[$winner['tax_id']][$winner['zip']] = $winner['zip'];
            }
        }

        $updated = 0;
        $cleared = 0;
        $unchanged = 0;

        foreach ($taxes as $tax) {
            $zips = collect($winners[$tax->id] ?? [])->sort()->values()->all();
            $ranges = $this->formatZipRanges($zips);
            $hints = $this->formatZipHints($zips);

            if ($this->sameValue($tax->zip_code_ranges, $ranges) && $this->sameValue($tax->zip_code_hints, $hints)) {
                $unchanged++;
                continue;
            }

            $tax->zip_code_ranges = $ranges;
            $tax->zip_code_hints = $hints;
            $tax->info = $this->withInfo((string)$tax->info);
            $tax->imported_at = Carbon::now();
            $tax->save();

            app(UsaTaxRegionRows::class)->syncForTax($tax->fresh('tax_group_code_rows') ?: $tax);
            $updated++;
            $cleared += $ranges ? 0 : 1;
        }

        return [
            'taxes' => $taxes->count(),
            'updated' => $updated,
            'cleared' => $cleared,
            'unchanged' => $unchanged,
            'conflict_zip_keys' => $conflicts,
        ];
    }

    protected function candidates($taxes): array
    {
        $candidates = [];

        foreach ($taxes as $tax) {
            $codes = $tax->taxGroupCodesList();

            if (!$codes) {
                $codes = [$tax->tax_group_code ?: 'UNKNOWN'];
            }

            foreach ($codes as $code) {
                foreach ($this->expandZipRanges((string)$tax->zip_code_ranges) as $zip) {
                    $key = implode('|', [
                        strtoupper((string)$tax->state_code),
                        $tax->tax_main_group,
                        $code,
                        $zip,
                    ]);
                    $candidates[$key][] = [
                        'tax_id' => (int)$tax->id,
                        'zip' => $zip,
                        'rate' => (float)($tax->rate_percent ?? $tax->percentage),
                        'priority' => $this->priority($tax),
                        'effective_from' => optional($tax->effective_from)->timestamp ?? 0,
                    ];
                }
            }
        }

        return $candidates;
    }

    protected function priority(Tax $tax): int
    {
        if ($tax->parser_name === 'WashingtonDorZip4Parser' || $tax->source_type === 'ZIP4_CSV') {
            return 100;
        }

        if ($tax->parser_name === 'UsaStateZipCoverage') {
            return 10;
        }

        if ($tax->source_type === 'SST_RATE_BOUNDARY') {
            return 80;
        }

        if (in_array($tax->source_type, ['DOWNLOADABLE_FILES', 'JSON', 'TXT_XLSX_HTML', 'XML_TEXT'], true)) {
            return 70;
        }

        if ($tax->source_type === 'HTML_TABLE') {
            return 60;
        }

        if ($tax->source_type === 'HTML') {
            return 50;
        }

        if ($tax->source_type === 'MANUAL') {
            return 20;
        }

        return 40;
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
            ->unique()
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

    protected function formatRange(int $start, int $end): string
    {
        $start = str_pad((string)$start, 5, '0', STR_PAD_LEFT);
        $end = str_pad((string)$end, 5, '0', STR_PAD_LEFT);

        return $start === $end ? $start : $start . '-' . $end;
    }

    protected function sameValue($left, $right): bool
    {
        return trim((string)$left) === trim((string)$right);
    }

    protected function withInfo(string $info): string
    {
        $info = trim($info);

        if (strpos($info, self::INFO) !== false) {
            return $info;
        }

        return trim($info . "\n" . self::INFO);
    }
}
