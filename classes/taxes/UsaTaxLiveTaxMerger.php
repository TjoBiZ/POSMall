<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\TaxGroupCode;

class UsaTaxLiveTaxMerger
{
    private const PIVOTS = [
        'kodzero_posmall_product_tax' => 'product_id',
        'kodzero_posmall_shipping_method_tax' => 'shipping_method_id',
        'kodzero_posmall_payment_method_tax' => 'payment_method_id',
        'kodzero_posmall_country_tax' => 'country_id',
        'kodzero_posmall_state_tax' => 'state_id',
        'kodzero_posmall_category_tax' => 'category_id',
    ];

    public function mergeCompatible(Tax $tax): Tax
    {
        $current = Tax::find($tax->id);

        if (!$current) {
            return $tax;
        }

        $tax = $current;
        $siblings = $this->siblings($tax);

        if ($siblings->count() <= 1) {
            $this->syncAggregateFields($tax, collect([$tax]));

            return $tax->fresh() ?: $tax;
        }

        /** @var Tax $keeper */
        $keeper = $siblings->sortBy('id')->first();

        $this->syncAggregateFields($keeper, $siblings);

        $siblings
            ->reject(fn (Tax $sibling) => $sibling->id === $keeper->id)
            ->each(function (Tax $duplicate) use ($keeper) {
                $this->movePivotLinks($duplicate, $keeper);
                $duplicate->delete();
            });

        return $keeper->fresh() ?: $keeper;
    }

    protected function siblings(Tax $tax): Collection
    {
        $mainGroup = $tax->tax_main_group;
        $rate = (float)($tax->rate_percent ?? $tax->percentage);

        return Tax::where('state_code', $tax->state_code)
            ->where('jurisdiction_code', $tax->jurisdiction_code)
            ->where('rate_percent', $rate)
            ->where(fn ($query) => $this->sourceBackedTaxQuery($query))
            ->get()
            ->filter(fn (Tax $candidate) => $candidate->tax_main_group === $mainGroup)
            ->values();
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

    protected function syncAggregateFields(Tax $keeper, Collection $siblings): void
    {
        $children = $this->childRows($siblings);
        $codes = $children->pluck('tax_group_code')->all();
        $descriptions = $children
            ->map(fn (array $child) => trim(implode(': ', array_filter([
                $child['tax_group_name'] ?: $child['tax_group_code'],
                $child['tax_group_description'],
            ]))))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$keeper->tax_group_code) {
            $keeper->tax_group_code = $codes[0] ?? null;
        }

        $keeper->tax_main_group = $keeper->tax_main_group ?: Tax::taxMainGroupForCodes($codes);
        $keeper->tax_main_group_name = $keeper->tax_main_group_name
            ?: (Tax::taxMainGroupOptions()[$keeper->tax_main_group] ?? 'General');
        $keeper->tax_group_name = count($codes) > 1
            ? sprintf('%d %s tax groups', count($codes), strtolower($keeper->tax_main_group_display))
            : ($children->first()['tax_group_name'] ?? $keeper->tax_group_name);
        $keeper->tax_group_description = count($descriptions) > 1
            ? implode("\n", array_map(fn ($description) => '- ' . $description, $descriptions))
            : ($descriptions[0] ?? $keeper->tax_group_description);
        $keeper->description = $this->description($keeper, $siblings, $descriptions);
        $keeper->zip_code_hints = $this->longest($siblings, 'zip_code_hints') ?: $keeper->zip_code_hints;
        $keeper->zip_code_ranges = $this->longest($siblings, 'zip_code_ranges') ?: $keeper->zip_code_ranges;
        $keeper->boundary_source_url = $this->summary($siblings, 'boundary_source_url') ?: $keeper->boundary_source_url;
        $keeper->source_name = $this->summary($siblings, 'source_name') ?: $keeper->source_name;
        $keeper->parser_name = $this->summary($siblings, 'parser_name') ?: $keeper->parser_name;
        $keeper->source_hash = hash('sha256', implode('|', $codes) . '|' . $this->summary($siblings, 'source_hash'));
        $keeper->imported_at = Carbon::now();
        $keeper->save();

        $this->syncChildRows($keeper, $children);
    }

    protected function childRows(Collection $siblings): Collection
    {
        return $siblings
            ->flatMap(function (Tax $tax) {
                $children = collect([[
                    'tax_group_code' => $tax->tax_group_code,
                    'tax_group_name' => $tax->tax_group_name,
                    'tax_group_description' => $tax->tax_group_description,
                ]]);

                return $children->concat($tax->tax_group_code_rows()->get()->map(fn (TaxGroupCode $row) => [
                    'tax_group_code' => $row->tax_group_code,
                    'tax_group_name' => $row->tax_group_name,
                    'tax_group_description' => $row->tax_group_description,
                ]));
            })
            ->filter(fn (array $child) => !empty($child['tax_group_code']))
            ->keyBy(fn (array $child) => (string)$child['tax_group_code'])
            ->sortKeys()
            ->values();
    }

    protected function syncChildRows(Tax $tax, Collection $children): void
    {
        $codes = $children->pluck('tax_group_code')->filter()->values()->all();

        if ($codes) {
            $tax->tax_group_code_rows()->whereNotIn('tax_group_code', $codes)->delete();
        } else {
            $tax->tax_group_code_rows()->delete();
        }

        $children->each(function (array $child) use ($tax) {
            $tax->tax_group_code_rows()->updateOrCreate(
                ['tax_group_code' => $child['tax_group_code']],
                [
                    'tax_group_name' => $child['tax_group_name'],
                    'tax_group_description' => $child['tax_group_description'],
                ]
            );
        });
    }

    protected function description(Tax $keeper, Collection $siblings, array $descriptions): ?string
    {
        $base = $siblings
            ->pluck('description')
            ->filter()
            ->first();

        if (count($descriptions) <= 1) {
            return $base ?: $keeper->description;
        }

        return trim(($base ?: '') . "\n\nCovered tax groups:\n" . implode("\n", array_map(
            fn ($description) => '- ' . $description,
            $descriptions
        )));
    }

    protected function summary(Collection $siblings, string $attribute): ?string
    {
        $values = $siblings
            ->pluck($attribute)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        if ($values->count() === 1) {
            return $values->first();
        }

        return 'Multiple: ' . $values->implode(', ');
    }

    protected function longest(Collection $siblings, string $attribute): ?string
    {
        return $siblings
            ->pluck($attribute)
            ->filter()
            ->sortByDesc(fn ($value) => strlen((string)$value))
            ->first();
    }

    protected function movePivotLinks(Tax $from, Tax $to): void
    {
        foreach (self::PIVOTS as $table => $otherKey) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('tax_id', $from->id)
                ->orderBy($otherKey)
                ->get()
                ->each(function ($pivot) use ($table, $otherKey, $from, $to) {
                    $exists = DB::table($table)
                        ->where('tax_id', $to->id)
                        ->where($otherKey, $pivot->{$otherKey})
                        ->exists();

                    if ($exists) {
                        DB::table($table)
                            ->where('tax_id', $from->id)
                            ->where($otherKey, $pivot->{$otherKey})
                            ->delete();

                        return;
                    }

                    DB::table($table)
                        ->where('tax_id', $from->id)
                        ->where($otherKey, $pivot->{$otherKey})
                        ->update(['tax_id' => $to->id]);
                });
        }
    }
}
