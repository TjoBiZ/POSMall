<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class UsaTaxRateGroup extends Model
{
    public $table = 'kodzero_posmall_usa_tax_rate_groups';

    public $fillable = [
        'name',
        'state_code',
        'tax_group_code',
        'tax_group_name',
        'tax_group_description',
        'rate_percent',
        'state_rate_percent',
        'local_rate_percent',
        'taxability_mode',
        'region_names',
        'county_names',
        'city_names',
        'zip_codes',
        'zip_ranges',
        'description',
        'info',
        'source_url',
        'source_type',
        'source_hash',
        'source_rows_count',
        'is_default',
        'is_active',
        'effective_from',
        'effective_to',
        'imported_at',
    ];

    public $casts = [
        'rate_percent' => 'float',
        'state_rate_percent' => 'float',
        'local_rate_percent' => 'float',
        'source_rows_count' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'imported_at' => 'datetime',
    ];
}
