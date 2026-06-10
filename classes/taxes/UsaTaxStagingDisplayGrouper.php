<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Illuminate\Support\Collection;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxImportStaging;

class UsaTaxStagingDisplayGrouper
{
    public function group($rows, ?Collection $liveTaxKeys = null): Collection
    {
        $liveTaxKeys ??= collect();

        return collect($rows)
            ->groupBy(fn (UsaTaxImportStaging $row) => $this->key($row))
            ->map(fn (Collection $group) => $this->displayRow($group, $liveTaxKeys))
            ->values();
    }

    protected function key(UsaTaxImportStaging $row): string
    {
        return implode('|', [
            $row->status,
            $row->state_code,
            $row->tax_main_group,
            $row->jurisdiction_type,
            $row->jurisdiction_name,
            $row->jurisdiction_code,
            number_format((float)$row->state_rate_percent, 4, '.', ''),
            number_format((float)$row->local_rate_percent, 4, '.', ''),
            number_format((float)$row->rate_percent, 4, '.', ''),
            $row->zip_code_ranges,
        ]);
    }

    public function liveTaxKeysFor($rows): Collection
    {
        $rows = collect($rows);
        $states = $rows->pluck('state_code')->filter()->unique()->values()->all();

        if (!$states) {
            return collect();
        }

        return Tax::with('tax_group_code_rows')
            ->where('is_enabled', true)
            ->where('is_active', true)
            ->whereIn('state_code', $states)
            ->get()
            ->flatMap(function (Tax $tax) {
                return collect($tax->taxGroupCodesList())
                    ->mapWithKeys(fn (string $code) => [$this->liveKeyFromTax($tax, $code) => true]);
            });
    }

    protected function displayRow(Collection $group, Collection $liveTaxKeys): object
    {
        /** @var UsaTaxImportStaging $first */
        $first = $group->first();
        $isImported = $this->isImported($group, $liveTaxKeys);
        $readyRows = $group->filter(fn (UsaTaxImportStaging $row) => !$isImported && in_array($row->status, [
            UsaTaxImportStaging::STATUS_PENDING,
            UsaTaxImportStaging::STATUS_PARSED,
            UsaTaxImportStaging::STATUS_IMPORTED,
        ], true));
        $codes = $group->pluck('tax_group_code')->filter()->unique()->sort()->values();
        $names = $group->pluck('tax_group_name')->filter()->unique()->sort()->values();

        return (object)[
            'id' => $first->id,
            'record_ids_csv' => $readyRows->pluck('id')->implode(','),
            'record_count' => $group->count(),
            'children' => $group->sortBy('tax_group_name')->values(),
            'status' => $first->status,
            'is_imported' => $isImported,
            'state_code' => $first->state_code,
            'tax_main_group' => $first->tax_main_group,
            'tax_main_group_display' => $first->tax_main_group_display,
            'parsed_name' => $this->name($first, $group->count()),
            'raw_name' => $first->raw_name,
            'jurisdiction_type' => $first->jurisdiction_type,
            'jurisdiction_name' => $first->jurisdiction_name,
            'jurisdiction_code' => $first->jurisdiction_code,
            'tax_group_code' => $codes->implode(', '),
            'tax_group_name' => $names->implode(', '),
            'tax_group_description' => $this->taxGroupDescription($group),
            'state_rate_percent' => $first->state_rate_percent,
            'local_rate_percent' => $first->local_rate_percent,
            'rate_percent' => $first->rate_percent,
            'zip_code_hints' => $first->zip_code_hints,
            'zip_code_ranges' => $first->zip_code_ranges,
            'boundary_source_url' => $first->boundary_source_url,
            'description' => $first->description,
            'source_type' => $this->summary($group, 'source_type'),
            'source_url' => $first->source_url,
            'source_name' => $this->summary($group, 'source_name'),
            'parser_name' => $this->summary($group, 'parser_name'),
            'error_message' => $first->error_message,
            'info' => $first->info,
            'source_hash' => $first->source_hash,
            'created_at' => $group->max('created_at'),
        ];
    }

    protected function name(UsaTaxImportStaging $row, int $count): string
    {
        if ($count === 1) {
            return (string)($row->parsed_name ?: $row->raw_name);
        }

        return sprintf(
            '%s %s %.2f%% grouped tax records',
            $row->state_code ?: 'US',
            $row->tax_main_group_display,
            (float)$row->rate_percent
        );
    }

    protected function isImported(Collection $group, Collection $liveTaxKeys): bool
    {
        return $group->isNotEmpty()
            && $group->every(fn (UsaTaxImportStaging $row) => $liveTaxKeys->has($this->liveKeyFromStaging($row)));
    }

    protected function liveKeyFromStaging(UsaTaxImportStaging $row): string
    {
        return implode('|', [
            $row->state_code,
            $row->jurisdiction_code,
            number_format((float)$row->rate_percent, 4, '.', ''),
            $row->tax_main_group,
            strtoupper((string)$row->tax_group_code),
        ]);
    }

    protected function liveKeyFromTax(Tax $tax, string $taxGroupCode): string
    {
        return implode('|', [
            $tax->state_code,
            $tax->jurisdiction_code,
            number_format((float)($tax->rate_percent ?? $tax->percentage), 4, '.', ''),
            $tax->tax_main_group,
            strtoupper($taxGroupCode),
        ]);
    }

    protected function taxGroupDescription(Collection $group): string
    {
        return $group
            ->map(fn (UsaTaxImportStaging $row) => trim(implode(': ', array_filter([
                $row->tax_group_name ?: $row->tax_group_code,
                $row->tax_group_description,
            ]))))
            ->filter()
            ->unique()
            ->implode(' | ');
    }

    protected function summary(Collection $group, string $attribute): ?string
    {
        $values = $group
            ->pluck($attribute)
            ->filter()
            ->unique()
            ->values();

        if ($values->count() <= 1) {
            return $values->first();
        }

        return sprintf('Multiple: %s', $values->implode(', '));
    }
}
