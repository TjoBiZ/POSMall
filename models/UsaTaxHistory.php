<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class UsaTaxHistory extends Model
{
    public $table = 'kodzero_posmall_usa_tax_histories';

    public $timestamps = false;

    public $fillable = [
        'tax_id',
        'old_rate_percent',
        'new_rate_percent',
        'state_code',
        'tax_group_code',
        'source_url',
        'source_hash',
        'effective_from',
        'effective_to',
        'changed_at',
        'created_at',
    ];

    public $casts = [
        'old_rate_percent' => 'float',
        'new_rate_percent' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public $belongsTo = [
        'tax' => Tax::class,
    ];
}
