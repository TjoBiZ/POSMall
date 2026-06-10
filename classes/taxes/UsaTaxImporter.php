<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxHistory;
use KodZero\POSMall\Models\UsaTaxImportStaging;
use Str;

class UsaTaxImporter
{
    public function stageStates(array $states, bool $starterOnly = false): string
    {
        $batchId = (string)Str::uuid();
        $states = collect($states)->map(fn ($state) => strtoupper((string)$state))->filter()->unique();

        foreach ($states as $state) {
            foreach (UsaTaxSourceRegistry::sources($state) as $source) {
                $source['starter_only'] = $starterOnly;
                $records = app(GeographicTaxRateGrouper::class)->group(
                    UsaTaxSourceRegistry::parserFor($source)->parse($source, $state)
                );

                if (!$records) {
                    continue;
                }

                foreach ($records as $record) {
                    $this->stageParsedRecord($batchId, $record);
                }
            }
        }

        return $batchId;
    }

    public function stageStarterRecords(): string
    {
        return $this->stageStates(UsaTaxSourceRegistry::starterStateCodes(), true);
    }

    protected function stageParsedRecord(string $batchId, array $record): void
    {
        $query = UsaTaxImportStaging::whereIn('status', [
                UsaTaxImportStaging::STATUS_PENDING,
                UsaTaxImportStaging::STATUS_PARSED,
                UsaTaxImportStaging::STATUS_IMPORTED,
            ])
            ->where('state_code', $record['state_code'] ?? null)
            ->where('tax_group_code', $record['tax_group_code'] ?? null)
            ->where('jurisdiction_code', $record['jurisdiction_code'] ?? null)
            ->where('source_url', $record['source_url'] ?? null)
            ->where('parser_name', $record['parser_name'] ?? null);

        $row = $query->first() ?: new UsaTaxImportStaging();
        $row->fill($record + [
            'batch_id' => $batchId,
            'status' => UsaTaxImportStaging::STATUS_PARSED,
            'error_message' => null,
        ]);
        $row->save();

        $query->where('id', '!=', $row->id)->delete();
    }

    public function importStaging(array $ids, bool $autoUpdateOnly = false): int
    {
        $count = 0;

        UsaTaxImportStaging::importable()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->each(function (UsaTaxImportStaging $row) use (&$count, $autoUpdateOnly) {
                if ($this->importRow($row, $autoUpdateOnly)) {
                    $count++;
                }
            });

        return $count;
    }

    public function deleteStaging(array $ids): int
    {
        $ids = array_filter($ids);

        if (!$ids) {
            return 0;
        }

        return UsaTaxImportStaging::whereIn('id', $ids)->delete();
    }

    protected function importRow(UsaTaxImportStaging $row, bool $autoUpdateOnly = false): bool
    {
        return DB::transaction(fn () => $this->importRowInTransaction($row, $autoUpdateOnly));
    }

    protected function importRowInTransaction(UsaTaxImportStaging $row, bool $autoUpdateOnly = false): bool
    {
        $autoUpdateTax = $autoUpdateOnly ? $this->autoUpdateTaxFor($row) : null;

        if ($autoUpdateOnly && !$autoUpdateTax) {
            return $this->skipRow($row, 'Skipped automatic update: no existing source-backed live tax is opted in for this staged row.');
        }

        $tax = $autoUpdateTax ?: Tax::where('state_code', $row->state_code)
            ->where('jurisdiction_code', $row->jurisdiction_code)
            ->where('rate_percent', (float)$row->rate_percent)
            ->where('tax_main_group', $row->tax_main_group)
            ->where(fn ($query) => $this->sourceBackedTaxQuery($query))
            ->first();

        if (!$tax) {
            $tax = $this->matchingGeographicRateGroupFor($row) ?: $this->singleChildTaxFor($row) ?: new Tax();
        }

        $oldRate = $tax->exists ? (float)($tax->rate_percent ?? $tax->percentage) : null;
        $newRate = (float)$row->rate_percent;

        if ($autoUpdateOnly) {
            if (!$tax->exists || !$tax->usa_auto_update_enabled || !$tax->isSourceBacked()) {
                return $this->skipRow($row, 'Skipped automatic update: this live tax is not source-backed and opted in for auto-update.');
            }

            if ($oldRate !== null && $oldRate > 0 && $newRate <= 0) {
                return $this->skipRow($row, sprintf(
                    'Skipped automatic update: positive-to-zero source returned %.4f%% for an existing %.4f%% tax. Review and import manually if this zero rate is correct.',
                    $newRate,
                    $oldRate
                ));
            }
        }

        if ($tax->exists && $oldRate !== $newRate) {
            UsaTaxHistory::create([
                'tax_id' => $tax->id,
                'old_rate_percent' => $oldRate,
                'new_rate_percent' => $newRate,
                'state_code' => $row->state_code,
                'tax_group_code' => $row->tax_group_code,
                'source_url' => $tax->source_url,
                'source_hash' => $tax->source_hash,
                'effective_from' => $tax->effective_from,
                'effective_to' => $tax->effective_to,
                'changed_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);
        }

        $zipCodeHints = $row->zip_code_hints;
        $zipCodeRanges = $row->zip_code_ranges;
        $boundarySourceUrl = $row->boundary_source_url;

        if ($tax->exists && !$zipCodeRanges && $tax->zip_code_ranges) {
            $zipCodeHints = $tax->zip_code_hints;
            $zipCodeRanges = $tax->zip_code_ranges;
            $boundarySourceUrl = $tax->boundary_source_url;
        }

        $tax->fill([
            'name' => $row->parsed_name,
            'percentage' => $newRate,
            'rate_percent' => $newRate,
            'state_code' => $row->state_code,
            'state_codes' => [$row->state_code],
            'tax_group_name' => $row->tax_group_name,
            'tax_group_description' => $row->tax_group_description,
            'taxability_mode' => $row->taxability_mode,
            'tax_main_group' => $row->tax_main_group,
            'tax_main_group_name' => $row->tax_main_group_display,
            'jurisdiction_type' => $row->jurisdiction_type,
            'jurisdiction_name' => $row->jurisdiction_name,
            'jurisdiction_code' => $row->jurisdiction_code,
            'state_rate_percent' => $row->state_rate_percent,
            'local_rate_percent' => $row->local_rate_percent,
            'zip_code_hints' => $zipCodeHints,
            'zip_code_ranges' => $zipCodeRanges,
            'boundary_source_url' => $boundarySourceUrl,
            'description' => $row->description,
            'source_url' => $row->source_url,
            'source_type' => $row->source_type,
            'source_name' => $row->source_name,
            'parser_name' => $row->parser_name,
            'source_hash' => $row->source_hash,
            'effective_from' => $row->effective_from,
            'effective_to' => $row->effective_to,
            'imported_at' => Carbon::now(),
            'is_enabled' => true,
            'is_active' => true,
        ]);

        if (!$tax->tax_group_code) {
            $tax->tax_group_code = $row->tax_group_code;
        }

        $tax->save();
        $tax->tax_group_code_rows()->updateOrCreate(
            ['tax_group_code' => $row->tax_group_code],
            [
                'tax_group_name' => $row->tax_group_name,
                'tax_group_description' => $row->tax_group_description,
            ]
        );
        $tax = app(UsaTaxLiveTaxMerger::class)->mergeCompatible($tax);
        app(UsaTaxRegionRows::class)->syncForTax($tax->fresh('tax_group_code_rows') ?: $tax);

        $row->status = UsaTaxImportStaging::STATUS_IMPORTED;
        $row->error_message = null;
        $row->save();

        return true;
    }

    protected function skipRow(UsaTaxImportStaging $row, string $message): bool
    {
        $row->status = UsaTaxImportStaging::STATUS_SKIPPED;
        $row->error_message = $message;
        $row->save();

        return false;
    }

    protected function autoUpdateTaxFor(UsaTaxImportStaging $row): ?Tax
    {
        return Tax::with('tax_group_code_rows')
            ->where('state_code', $row->state_code)
            ->where('jurisdiction_code', $row->jurisdiction_code)
            ->where('tax_main_group', $row->tax_main_group)
            ->where('usa_auto_update_enabled', true)
            ->where(fn ($query) => $this->sourceBackedTaxQuery($query))
            ->get()
            ->first(function (Tax $tax) use ($row) {
                return $tax->isSourceBacked()
                    && $tax->matchesTaxGroupCode($row->tax_group_code);
            });
    }

    protected function singleChildTaxFor(UsaTaxImportStaging $row): ?Tax
    {
        return Tax::with('tax_group_code_rows')
            ->where('state_code', $row->state_code)
            ->where('jurisdiction_code', $row->jurisdiction_code)
            ->where('tax_main_group', $row->tax_main_group)
            ->where(fn ($query) => $this->sourceBackedTaxQuery($query))
            ->get()
            ->first(function (Tax $tax) use ($row) {
                return $tax->matchesTaxGroupCode($row->tax_group_code)
                    && count($tax->taxGroupCodesList()) <= 1;
            });
    }

    protected function matchingGeographicRateGroupFor(UsaTaxImportStaging $row): ?Tax
    {
        if ($row->jurisdiction_type !== 'geographic_tax_rate_group') {
            return null;
        }

        return Tax::with('tax_group_code_rows')
            ->where('state_code', $row->state_code)
            ->where('rate_percent', (float)$row->rate_percent)
            ->where('tax_main_group', $row->tax_main_group)
            ->where('jurisdiction_type', $row->jurisdiction_type)
            ->where('source_name', $row->source_name)
            ->where(fn ($query) => $this->sourceBackedTaxQuery($query))
            ->orderBy('id')
            ->first();
    }

    protected function sourceBackedTaxQuery($query)
    {
        return $query
            ->whereNull('source_type')
            ->orWhere('source_type', '!=', 'MANUAL')
            ->orWhereNotNull('source_url')
            ->orWhereNotNull('source_name')
            ->orWhereNotNull('parser_name');
    }

}
