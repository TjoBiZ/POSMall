<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;
use KodZero\POSMall\Models\Tax;

class SstTaxabilityMatrixParser extends SeedRuleSourceParser
{
    private const API_BASE = 'https://sst.streamlinedsalestax.org';

    private static array $stateIdsCache = [];
    private static array $formsCache = [];
    private static array $rowsCache = [];

    private const GROUP_REFERENCES = [
        'DIGITAL_FILE_ONLY' => [
            '31040', '31050', '31060', '31070', '31080', '31090', '31100', '31110', '31120',
        ],
        'PREWRITTEN_SOFTWARE_ELECTRONIC' => ['30050'],
        'CUSTOM_SOFTWARE_DEV' => ['30025'],
        'CUSTOM_MODIFICATION_SEPARATE' => ['30025'],
        'DIGITAL_AUTOMATED_SERVICE' => ['31000'],
    ];

    public function parse(array $source, string $stateCode): array
    {
        $stateCode = strtoupper($stateCode);

        if (($source['code'] ?? null) !== 'SST_TAXABILITY_MATRIX' || !empty($source['starter_only'])) {
            return parent::parse($source, $stateCode);
        }

        $form = $this->latestForm($source, $stateCode);
        if (!$form) {
            return [];
        }

        $rows = $this->rowsByReference($this->rows($source, (int)$form['formId']));
        if (!$rows) {
            return [];
        }

        $records = [];
        foreach ($this->taxabilityByGroup($rows) as $groupCode => $taxability) {
            if ($taxability['mode'] === 'sst_exempt') {
                $records[] = $this->zeroRateRecord($source, $stateCode, $groupCode, $form, $taxability);
                continue;
            }

            $records = array_merge(
                $records,
                $this->clonePhysicalRateRows($source, $stateCode, $groupCode, $form, $taxability)
            );
        }

        return $records;
    }

    protected function taxabilityByGroup(array $rows): array
    {
        $out = [];

        foreach (self::GROUP_REFERENCES as $groupCode => $refs) {
            $matches = collect($refs)
                ->map(fn (string $ref) => $rows[$ref] ?? null)
                ->filter()
                ->values();

            if ($matches->isEmpty()) {
                continue;
            }

            $taxable = $matches->filter(fn (array $row) => $this->taxable($row['taxable'] ?? null))->count();
            $out[$groupCode] = [
                'mode' => $taxable === 0
                    ? 'sst_exempt'
                    : ($taxable === $matches->count() ? 'sst_taxable' : 'sst_partial_taxable_review'),
                'refs' => $matches->pluck('ref')->implode(', '),
                'labels' => $matches->pluck('label')->implode('; '),
                'taxable_rows' => $taxable,
                'matched_rows' => $matches->count(),
            ];
        }

        return $out;
    }

    protected function clonePhysicalRateRows(array $source, string $stateCode, string $groupCode, array $form, array $taxability): array
    {
        $physicalTaxes = $this->physicalTaxes($stateCode);
        if ($physicalTaxes->isEmpty()) {
            return [$this->baseRateRecord($source, $stateCode, $groupCode, $form, $taxability)];
        }

        return $physicalTaxes
            ->map(fn (Tax $tax) => $this->recordFromPhysicalTax($source, $tax, $groupCode, $form, $taxability))
            ->all();
    }

    protected function recordFromPhysicalTax(array $source, Tax $tax, string $groupCode, array $form, array $taxability): array
    {
        [$groupName, $groupDescription] = UsaTaxSourceRegistry::taxGroups()[$groupCode] ?? [$groupCode, null];
        $rate = (float)($tax->rate_percent ?? $tax->percentage);
        $sourceUrl = $this->rowsUrl((int)$form['formId']);

        return [
            'state_code' => strtoupper((string)$tax->state_code),
            'source_url' => $sourceUrl,
            'source_type' => 'SST_TAXABILITY_MATRIX_API',
            'source_name' => $source['name'] ?? 'SST Taxability Matrix',
            'parser_name' => class_basename(static::class),
            'raw_name' => sprintf('%s %s via SST Matrix %.1f', $tax->state_code, $groupCode, (float)$form['version']),
            'parsed_name' => sprintf('%s %.2f%% %s SST Taxability Region', $tax->state_code, $rate, $groupName),
            'tax_group_code' => $groupCode,
            'tax_group_name' => $groupName,
            'tax_group_description' => $groupDescription,
            'taxability_mode' => $taxability['mode'],
            'jurisdiction_type' => $tax->jurisdiction_type,
            'jurisdiction_name' => $tax->jurisdiction_name ?: sprintf('%s statewide/base SST taxability', $tax->state_code),
            'jurisdiction_code' => $tax->jurisdiction_code,
            'state_rate_percent' => (float)($tax->state_rate_percent ?? $rate),
            'local_rate_percent' => (float)($tax->local_rate_percent ?? 0),
            'rate_percent' => $rate,
            'zip_code_hints' => $tax->zip_code_hints,
            'zip_code_ranges' => $tax->zip_code_ranges,
            'boundary_source_url' => $tax->boundary_source_url,
            'description' => $this->matrixDescription($groupCode, $taxability, $form),
            'info' => 'Coverage: SST Taxability Matrix says this tax group is taxable or partially taxable; regional rates and ZIP ranges are copied from the state physical goods rate/boundary layer.',
            'source_rows_count' => $taxability['matched_rows'],
            'source_hash' => hash('sha256', implode('|', [
                $sourceUrl,
                $tax->id,
                $groupCode,
                $taxability['mode'],
                $rate,
                $tax->zip_code_ranges,
            ])),
        ];
    }

    protected function zeroRateRecord(array $source, string $stateCode, string $groupCode, array $form, array $taxability): array
    {
        return $this->standaloneRecord($source, $stateCode, $groupCode, $form, $taxability, 0.0);
    }

    protected function baseRateRecord(array $source, string $stateCode, string $groupCode, array $form, array $taxability): array
    {
        return $this->standaloneRecord($source, $stateCode, $groupCode, $form, $taxability, $this->statePhysicalRate($stateCode));
    }

    protected function standaloneRecord(array $source, string $stateCode, string $groupCode, array $form, array $taxability, float $rate): array
    {
        [$groupName, $groupDescription] = UsaTaxSourceRegistry::taxGroups()[$groupCode] ?? [$groupCode, null];
        $sourceUrl = $this->rowsUrl((int)$form['formId']);

        return [
            'state_code' => $stateCode,
            'source_url' => $sourceUrl,
            'source_type' => 'SST_TAXABILITY_MATRIX_API',
            'source_name' => $source['name'] ?? 'SST Taxability Matrix',
            'parser_name' => class_basename(static::class),
            'raw_name' => sprintf('%s %s via SST Matrix %.1f', $stateCode, $groupCode, (float)$form['version']),
            'parsed_name' => sprintf('%s %.2f%% %s SST Taxability', $stateCode, $rate, $groupName),
            'tax_group_code' => $groupCode,
            'tax_group_name' => $groupName,
            'tax_group_description' => $groupDescription,
            'taxability_mode' => $taxability['mode'],
            'jurisdiction_type' => 'statewide_taxability',
            'jurisdiction_name' => $stateCode . ' statewide SST taxability',
            'jurisdiction_code' => null,
            'state_rate_percent' => $rate,
            'local_rate_percent' => 0,
            'rate_percent' => $rate,
            'description' => $this->matrixDescription($groupCode, $taxability, $form),
            'info' => 'Coverage: statewide SST Taxability Matrix record. ZIP fallback ranges are filled by Census ZCTA coverage when available.',
            'source_rows_count' => $taxability['matched_rows'],
            'source_hash' => hash('sha256', implode('|', [$sourceUrl, $stateCode, $groupCode, $taxability['mode'], $rate])),
        ];
    }

    protected function matrixDescription(string $groupCode, array $taxability, array $form): string
    {
        return sprintf(
            'SST Taxability Matrix Library of Definitions %.1f. Tax group %s matched refs %s: %d taxable row(s) of %d. Labels: %s.',
            (float)$form['version'],
            $groupCode,
            $taxability['refs'],
            $taxability['taxable_rows'],
            $taxability['matched_rows'],
            $taxability['labels']
        );
    }

    protected function latestForm(array $source, string $stateCode): ?array
    {
        $stateId = $this->stateIds($source)[$stateCode] ?? null;
        if (!$stateId) {
            return null;
        }

        return collect($this->forms($source))
            ->flatMap(fn (array $form) => $form['versions'] ?? [])
            ->filter(fn (array $version) => !empty($version['published']) && (int)($version['stateId'] ?? 0) === $stateId)
            ->sortByDesc(fn (array $version) => (float)($version['version'] ?? 0))
            ->first();
    }

    protected function stateIds(array $source): array
    {
        if (!empty($source['taxability_state_ids']) && is_array($source['taxability_state_ids'])) {
            return $source['taxability_state_ids'];
        }

        if (!self::$stateIdsCache) {
            self::$stateIdsCache = collect($this->json(self::API_BASE . '/api/states'))
                ->mapWithKeys(function (array $state) {
                    $abbr = strtoupper((string)($state['abbreviation'] ?? ''));
                    $id = (int)($state['stateId'] ?? 0);

                    return $id > 0 && preg_match('/^[A-Z]{2}$/', $abbr) ? [$abbr => $id] : [];
                })
                ->all();
        }

        return self::$stateIdsCache;
    }

    protected function forms(array $source): array
    {
        if (!empty($source['taxability_forms']) && is_array($source['taxability_forms'])) {
            return $source['taxability_forms'];
        }

        if (!self::$formsCache) {
            self::$formsCache = $this->json(self::API_BASE . '/api/forms/FormType/1');
        }

        return self::$formsCache;
    }

    protected function rows(array $source, int $formId): array
    {
        if (!empty($source['taxability_rows'][$formId]) && is_array($source['taxability_rows'][$formId])) {
            return $source['taxability_rows'][$formId];
        }

        if (!array_key_exists($formId, self::$rowsCache)) {
            self::$rowsCache[$formId] = $this->json($this->rowsUrl($formId));
        }

        return self::$rowsCache[$formId];
    }

    protected function rowsUrl(int $formId): string
    {
        return self::API_BASE . '/api/forms/' . $formId . '/rows';
    }

    protected function json(string $url): array
    {
        $json = $this->fetchSource($url);
        $data = json_decode((string)$json, true);

        return is_array($data) ? $data : [];
    }

    protected function rowsByReference(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $columns = $this->columns($row);
            $ref = trim((string)($columns[0] ?? ''));

            if (!preg_match('/^\d{5}$/', $ref)) {
                continue;
            }

            $out[$ref] = [
                'ref' => $ref,
                'label' => trim((string)($columns[1] ?? '')),
                'taxable' => trim((string)($columns[2] ?? '')),
            ];
        }

        return $out;
    }

    protected function columns(array $row): array
    {
        $columns = $row['displayColumns'] ?? [];
        usort($columns, fn ($a, $b) => ((int)($a['orderId'] ?? 0)) <=> ((int)($b['orderId'] ?? 0)));

        return array_map(function (array $column) {
            $value = ($column['value'] ?? null) !== null ? $column['value'] : ($column['tValue'] ?? '');

            return trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$value))));
        }, $columns);
    }

    protected function taxable(?string $value): bool
    {
        $value = trim((string)$value);

        return is_numeric($value) && (float)$value > 0;
    }

    protected function physicalTaxes(string $stateCode)
    {
        return Tax::with('tax_group_code_rows')
            ->where('state_code', $stateCode)
            ->where('is_enabled', true)
            ->where(function ($query) {
                $query->where('tax_group_code', 'PHYSICAL_TPP')
                    ->orWhereHas('tax_group_code_rows', fn ($query) => $query->where('tax_group_code', 'PHYSICAL_TPP'));
            })
            ->where(function ($query) {
                $query->whereNull('source_type')->orWhere('source_type', '!=', 'MANUAL');
            })
            ->orderByRaw('zip_code_ranges is null')
            ->orderBy('id')
            ->get();
    }

    protected function statePhysicalRate(string $stateCode): float
    {
        foreach (UsaTaxSourceRegistry::seedRules() as $rule) {
            if ($rule[0] === $stateCode && $rule[1] === 'PHYSICAL_TPP') {
                return (float)$rule[2];
            }
        }

        return 0.0;
    }
}
