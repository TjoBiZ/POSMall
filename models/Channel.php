<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\Sluggable;
use October\Rain\Database\Traits\Validation;

class Channel extends Model
{
    use Sluggable;
    use Validation;

    public $table = 'kodzero_posmall_channels';

    public $rules = [
        'name' => 'required',
        'slug' => 'required',
        'type' => 'required',
    ];

    public $fillable = [
        'name',
        'slug',
        'type',
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
