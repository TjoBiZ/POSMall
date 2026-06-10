<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\Sluggable;
use October\Rain\Database\Traits\Validation;

class Vendor extends Model
{
    use Sluggable;
    use Validation;

    public $table = 'kodzero_posmall_vendors';

    public $rules = [
        'name' => 'required',
        'slug' => 'required',
        'contact_email' => 'nullable|email',
    ];

    public $fillable = [
        'name',
        'slug',
        'contact_email',
        'is_active',
        'is_default',
    ];

    public $slugs = [
        'slug' => 'name',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];
}
