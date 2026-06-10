<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;

class TaxGroupCode extends Model
{
    public $table = 'kodzero_posmall_tax_group_codes';

    public $fillable = [
        'tax_id',
        'tax_group_code',
        'tax_group_name',
        'tax_group_description',
    ];

    public $belongsTo = [
        'tax' => [
            Tax::class,
            'key' => 'tax_id',
        ],
    ];
}
