<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxRegionRow;

class UsaTaxRegionRows
{
    public function syncForTax(Tax $tax): void
    {
        if (!$tax->exists) {
            return;
        }

        DB::transaction(function () use ($tax) {
            UsaTaxRegionRow::where('tax_id', $tax->id)->delete();

            $ranges = $this->ranges((string)$tax->zip_code_ranges);

            if (!$ranges) {
                return;
            }

            foreach ($tax->taxGroupCodesList() as $groupCode) {
                foreach ($ranges as $range) {
                    UsaTaxRegionRow::create([
                        'tax_id' => $tax->id,
                        'state_code' => $tax->state_code,
                        'tax_main_group' => $tax->tax_main_group,
                        'jurisdiction_name' => $tax->jurisdiction_name,
                        'jurisdiction_code' => $tax->jurisdiction_code,
                        'zip_code' => $range['from'] === $range['to'] ? $range['from'] : null,
                        'zip_from' => $range['from'],
                        'zip_to' => $range['to'],
                        'state_rate_percent' => $tax->state_rate_percent,
                        'local_rate_percent' => $tax->local_rate_percent,
                        'total_rate_percent' => $tax->rate_percent ?? $tax->percentage,
                        'tax_group_code' => $groupCode,
                        'taxability_mode' => $tax->taxability_mode,
                        'source_url' => $tax->source_url,
                        'source_type' => $tax->source_type,
                        'source_hash' => $tax->source_hash,
                        'effective_from' => $tax->effective_from,
                        'effective_to' => $tax->effective_to,
                    ]);
                }
            }
        });
    }

    public function findTax(?string $stateCode, ?string $zipCode, ?string $taxGroupCode = null): ?Tax
    {
        $stateCode = $stateCode ? strtoupper($stateCode) : null;
        [$zipCode, $zip4] = $this->zipParts($zipCode);

        if (!$stateCode || !$zipCode) {
            return null;
        }

        if ($zip4) {
            $tax = $this->taxFromQuery(
                $this->regionQuery($stateCode, $zipCode, $taxGroupCode)
                    ->whereNotNull('zip4_from')
                    ->whereNotNull('zip4_to')
                    ->where('zip4_from', '<=', $zip4)
                    ->where('zip4_to', '>=', $zip4)
            );

            if ($tax) {
                return $tax;
            }
        }

        return $this->taxFromQuery(
            $this->regionQuery($stateCode, $zipCode, $taxGroupCode)
                ->whereNull('zip4_from')
                ->whereNull('zip4_to')
        );
    }

    public function ranges(?string $ranges): array
    {
        if (!$ranges) {
            return [];
        }

        return collect(preg_split('/\s*,\s*/', $ranges) ?: [])
            ->map(fn ($range) => $this->range($range))
            ->filter()
            ->unique(fn ($range) => $range['from'] . '-' . $range['to'])
            ->values()
            ->all();
    }

    protected function range(?string $range): ?array
    {
        $range = trim((string)$range);

        if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $range, $matches)) {
            $from = min($matches[1], $matches[2]);
            $to = max($matches[1], $matches[2]);

            return ['from' => $from, 'to' => $to];
        }

        if (preg_match('/^\d{5}$/', $range)) {
            return ['from' => $range, 'to' => $range];
        }

        return null;
    }

    protected function regionQuery(string $stateCode, string $zipCode, ?string $taxGroupCode)
    {
        $query = UsaTaxRegionRow::where('state_code', $stateCode)
            ->whereNotNull('tax_id')
            ->where('zip_from', '<=', $zipCode)
            ->where('zip_to', '>=', $zipCode);

        if ($taxGroupCode) {
            $query->where('tax_group_code', strtoupper(trim($taxGroupCode)));
        }

        return $query;
    }

    protected function taxFromQuery($query): ?Tax
    {
        $rows = $query
            ->orderByDesc('total_rate_percent')
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            $tax = Tax::with('tax_group_code_rows')
                ->where('is_enabled', true)
                ->where('is_active', true)
                ->find($row->tax_id);

            if ($tax) {
                return $tax;
            }
        }

        return null;
    }

    protected function zipParts(?string $zipCode): array
    {
        preg_match('/(\d{5})(?:\D*(\d{4}))?/', (string)$zipCode, $matches);

        return [$matches[1] ?? null, $matches[2] ?? null];
    }
}
