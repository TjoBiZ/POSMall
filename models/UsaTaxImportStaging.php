<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Models\Tax;

class UsaTaxImportStaging extends Model
{
    use Validation;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARSED = 'parsed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SKIPPED = 'skipped';

    public $table = 'kodzero_posmall_usa_tax_import_staging';

    public $rules = [
        'batch_id' => 'required',
        'status' => 'required',
    ];

    public $fillable = [
        'batch_id',
        'state_code',
        'source_url',
        'source_type',
        'source_name',
        'parser_name',
        'raw_name',
        'parsed_name',
        'tax_group_code',
        'tax_group_name',
        'tax_group_description',
        'taxability_mode',
        'jurisdiction_type',
        'jurisdiction_name',
        'jurisdiction_code',
        'state_rate_percent',
        'local_rate_percent',
        'zip_code_hints',
        'zip_code_ranges',
        'region_names',
        'county_names',
        'city_names',
        'zip_codes',
        'zip_ranges',
        'boundary_source_url',
        'rate_percent',
        'description',
        'info',
        'source_rows_count',
        'effective_from',
        'effective_to',
        'source_hash',
        'status',
        'error_message',
    ];

    public $casts = [
        'rate_percent' => 'float',
        'state_rate_percent' => 'float',
        'local_rate_percent' => 'float',
        'source_rows_count' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeReady($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARSED]);
    }

    public function scopeImportable($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_PARSED,
            self::STATUS_IMPORTED,
        ]);
    }

    public function getTaxMainGroupAttribute(): string
    {
        return Tax::taxMainGroupForCode((string)$this->tax_group_code);
    }

    public function getTaxMainGroupDisplayAttribute(): string
    {
        return Tax::taxMainGroupOptions()[$this->tax_main_group] ?? 'General';
    }

    public function scopeTaxMainGroup($query, $groups): void
    {
        $groups = collect((array)$groups)
            ->map(fn ($group) => strtolower((string)$group))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$groups) {
            return;
        }

        $codes = collect($groups)
            ->flatMap(fn ($group) => Tax::taxGroupCodesByMainGroup()[$group] ?? [])
            ->unique()
            ->values()
            ->all();

        $query->where(function ($query) use ($groups, $codes) {
            if ($codes) {
                $query->whereIn('tax_group_code', $codes);
            }

            if (in_array(Tax::TAX_MAIN_GROUP_GENERAL, $groups, true)) {
                $codes ? $query->orWhereNull('tax_group_code') : $query->whereNull('tax_group_code');
            }
        });
    }
}
