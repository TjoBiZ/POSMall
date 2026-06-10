<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Traits\HashIds;
use RainLab\Location\Behaviors\LocationModel;
use System\Classes\PluginManager;

class Address extends Model
{
    use HashIds;
    use SoftDelete;
    use Validation;

    public $implement = [LocationModel::class];

    public $rules = [
        'lines'       => 'required',
        'zip'         => 'required',
        'country_id'  => 'bail|required|integer|exists:rainlab_location_countries,id',
        'state_id'    => 'bail|nullable|integer|exists:rainlab_location_states,id',
        'customer_id' => 'bail|required|integer|exists:kodzero_posmall_customers,id',
        'city'        => 'required',
    ];

    public $fillable = [
        'company',
        'name',
        'lines',
        'zip',
        'country_id',
        'city',
        'state_id',
        'details',
        'delivery_notes',
    ];

    public $hidden = [
        'id',
        'customer_id',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public $appends = ['hash_id'];

    public $table = 'kodzero_posmall_addresses';

    public $belongsTo = [
        'customer' => Customer::class,
    ];

    protected $dates = ['deleted_at'];

    public function beforeValidate()
    {
        if (in_array($this->state_id, ['', '0', 0], true)) {
            $this->state_id = null;
        }

        if (PluginManager::instance()->hasPlugin('Winter.Location')) {
            $this->rules['country_id'] = str_replace('rainlab_', 'winter_', $this->rules['country_id']);
        }
    }

    public function getNameAttribute()
    {
        return $this->original['name'] ?? optional($this->customer)->name;
    }

    public function getOneLinerAttribute(): string
    {
        $parts = array_filter([
            $this->name,
            $this->lines,
            $this->zip . ' ' . $this->city,
            $this->county_or_province,
            $this->country->name,
        ]);

        return implode(', ', $parts);
    }

    public function getLinesArrayAttribute(): array
    {
        return explode("\n", $this->lines);
    }

    public function getNamesArrayAttribute(): array
    {
        $names = explode("\n", $this->name);

        if (count($names)) {
            return $names;
        }

        return [$this->customer->firstname, $this->customer->lastname];
    }

    public static function byCustomer(Customer $customer)
    {
        return self::where('customer_id', $customer->id);
    }

    public function getCustomerOptions()
    {
        return Customer::with('user')->get()->mapWithKeys(fn (Customer $customer) => [
            $customer->id => sprintf('%s (%s)', $customer->name, optional($customer->user)->email),
        ])->toArray();
    }

    public function toArray()
    {
        return [
            'id'          => $this->id,
            'company'     => $this->company,
            'name'        => $this->name,
            'lines'       => $this->lines,
            'zip'         => $this->zip,
            'city'        => $this->city,
            'state_id'    => $this->state_id,
            'state'       => $this->state,
            'country_id'  => $this->country_id,
            'country'     => $this->country,
            'details'     => $this->details,
            'delivery_notes' => $this->delivery_notes,
        ];
    }
}
