<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class UsaTaxRegionRow extends Model
{
    public $table = 'kodzero_posmall_usa_tax_region_rows';

    public $fillable = [
        'batch_id',
        'group_id',
        'tax_id',
        'state_code',
        'tax_main_group',
        'county_name',
        'city_name',
        'region_name',
        'jurisdiction_name',
        'jurisdiction_code',
        'zip_code',
        'zip_from',
        'zip_to',
        'zip4_from',
        'zip4_to',
        'state_rate_percent',
        'county_rate_percent',
        'city_rate_percent',
        'district_rate_percent',
        'local_rate_percent',
        'total_rate_percent',
        'tax_group_code',
        'taxability_mode',
        'source_url',
        'source_type',
        'source_hash',
        'raw_payload',
        'effective_from',
        'effective_to',
    ];

    public $casts = [
        'group_id' => 'integer',
        'tax_id' => 'integer',
        'state_rate_percent' => 'float',
        'county_rate_percent' => 'float',
        'city_rate_percent' => 'float',
        'district_rate_percent' => 'float',
        'local_rate_percent' => 'float',
        'total_rate_percent' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];
}
