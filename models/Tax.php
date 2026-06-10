<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Illuminate\Support\Facades\Cache;
use Model;
use October\Rain\Database\Collection;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Database\IsStates;
use Rainlab\Location\Models\Country as RainLabCountry;
use Rainlab\Location\Models\State as RainLabState;

class Tax extends Model
{
    use IsStates;
    use Validation;

    public const TAX_MAIN_GROUP_PHYSICAL = 'physical';
    public const TAX_MAIN_GROUP_SERVICE = 'service';
    public const TAX_MAIN_GROUP_VIRTUAL = 'virtual';
    public const TAX_MAIN_GROUP_GENERAL = 'general';

    private const USA_STATE_CODE_PATTERN = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY|DC';

    private const USA_STATE_CODES_LIST_PATTERN = '/^\s*(?:'
        . self::USA_STATE_CODE_PATTERN
        . ')(?:\s*[,;.]\s*(?:'
        . self::USA_STATE_CODE_PATTERN
        . '))*\s*[,;.]?\s*$/i';

    /**
     * Default cache key for the queries taxes.
     * @var string
     */
    public const DEFAULT_TAX_CACHE_KEY = 'posmall.taxes.default';

    /**
     * Disable `is_default` handler on IsStates trait. Even if Tax uses a default value, the
     * current IsStates trait does not support multiple defaults, especially when using an
     * additional linking table (`kodzero_posmall_country_tax`).
     * @var null|string
     */
    public const IS_DEFAULT = null;

    /**
     * Enable `is_enabled` handler on IsStates trait, by passing the column name.
     * @var null|string
     */
    public const IS_ENABLED = 'is_enabled';

    /**
     * Implement behaviors for this model.
     * @var array
     */
    public $implement = [
        '@RainLab.Translate.Behaviors.TranslatableModel',
    ];
    
    /**
     * The table associated with this model.
     * @var string
     */
    public $table = 'kodzero_posmall_taxes';
    
    /**
     * The translatable attributes of this model.
     * @var array
     */
    public $translatable = [
        'name',
    ];

    /**
     * The validation rules for the single attributes.
     * @var array
     */
    public $rules = [
        'name'          => 'required',
        'percentage'    => 'numeric|min:0|max:100',
        'rate_percent'  => 'nullable|numeric|min:0|max:100',
        'state_code'    => ['nullable', 'regex:' . self::USA_STATE_CODES_LIST_PATTERN],
        'state_codes'   => ['nullable', 'regex:' . self::USA_STATE_CODES_LIST_PATTERN],
        'is_enabled'    => 'nullable|boolean',
        'is_active'     => 'nullable|boolean',
        'usa_auto_update_enabled' => 'nullable|boolean',
    ];

    /**
     * The attributes that are mass assignable.
     * @var array<string>
     */
    public $fillable = [
        'name',
        'percentage',
        'rate_percent',
        'state_code',
        'state_codes',
        'tax_group_code',
        'tax_group_name',
        'tax_group_description',
        'tax_main_group',
        'tax_main_group_name',
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
        'description',
        'info',
        'source_rows_count',
        'is_default',
        'is_enabled',
        'is_active',
        'source_url',
        'source_type',
        'source_name',
        'parser_name',
        'source_hash',
        'usa_auto_update_enabled',
        'effective_from',
        'effective_to',
        'imported_at',
    ];

    /**
     * The attributes that should be cast.
     * @var array
     */
    public $casts = [
        'is_default' => 'boolean',
        'is_enabled' => 'boolean',
        'is_active' => 'boolean',
        'usa_auto_update_enabled' => 'boolean',
        'rate_percent' => 'float',
        'state_rate_percent' => 'float',
        'local_rate_percent' => 'float',
        'source_rows_count' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'imported_at' => 'datetime',
    ];

    /**
     * The belongsToMany relationships of this model.
     * @var array
     */
    public $belongsToMany = [
        'products'         => [
            Product::class,
            'table'    => 'kodzero_posmall_product_tax',
            'key'      => 'tax_id',
            'otherKey' => 'product_id',
        ],
        'shipping_methods' => [
            ShippingMethod::class,
            'table'    => 'kodzero_posmall_shipping_method_tax',
            'key'      => 'tax_id',
            'otherKey' => 'shipping_method_id',
        ],
        'payment_methods'  => [
            PaymentMethod::class,
            'table'    => 'kodzero_posmall_payment_method_tax',
            'key'      => 'tax_id',
            'otherKey' => 'payment_method_id',
        ],
        'countries'        => [
            RainLabCountry::class,
            'table'      => 'kodzero_posmall_country_tax',
            'key'        => 'tax_id',
            'otherKey'   => 'country_id',
            'conditions' => 'is_enabled = true',
        ],
        'states'           => [
            RainLabState::class,
            'table'      => 'kodzero_posmall_state_tax',
            'key'        => 'tax_id',
            'otherKey'   => 'state_id',
            'conditions' => 'is_enabled = true',
        ],
        'categories'       => [
            Category::class,
            'table'    => 'kodzero_posmall_category_tax',
            'key'      => 'tax_id',
            'otherKey' => 'category_id',
        ],
    ];

    public $hasMany = [
        'tax_group_code_rows' => [
            TaxGroupCode::class,
            'key' => 'tax_id',
            'delete' => true,
        ],
        'usa_tax_region_rows' => [
            UsaTaxRegionRow::class,
            'key' => 'tax_id',
            'delete' => true,
        ],
    ];

    public function tax_group_code_rows()
    {
        return $this->hasMany(TaxGroupCode::class, 'tax_id');
    }

    public function usa_tax_region_rows()
    {
        return $this->hasMany(UsaTaxRegionRow::class, 'tax_id');
    }

    /**
     * Returns the default taxes.
     * @return Collection<Tax>|Tax[]
     */
    public static function defaultTaxes(): Collection
    {
        $taxes = Cache::rememberForever(static::DEFAULT_TAX_CACHE_KEY, function () {
            $columns = [
                'id',
                'name',
                'percentage',
                'rate_percent',
                'is_default',
                'is_enabled',
                'is_active',
                'state_code',
                'state_codes',
                'tax_group_code',
                'tax_main_group',
                'tax_main_group_name',
                'zip_code_ranges',
                'jurisdiction_code',
            ];
            $taxes = static::where('is_default', true)->get($columns);

            if (!$taxes) {
                return [];
            } else {
                // Make sure the "translations" relation is not cached.
                return $taxes->map->only($columns)->toArray();
            }
        });

        if (!$taxes) {
            return new Collection();
        } else {
            return self::hydrate($taxes);
        }
    }

    /**
     * Hook after model has been saved.
     * @return void
     */
    public function afterSave()
    {
        Cache::forget(self::DEFAULT_TAX_CACHE_KEY);
    }

    public function beforeValidate()
    {
        if ($this->rate_percent === null || $this->rate_percent === '') {
            $this->rate_percent = $this->percentage;
        }

        if ($this->percentage === null || $this->percentage === '') {
            $this->percentage = $this->rate_percent;
        }

        $rawPrimaryStateCode = $this->state_code ? strtoupper((string)$this->state_code) : null;
        $rawStateCodes = $this->attributes['state_codes'] ?? null;

        if (is_string($rawPrimaryStateCode) && strpbrk($rawPrimaryStateCode, ',;.') !== false) {
            $rawStateCodes = $rawStateCodes
                ? trim((string)$rawPrimaryStateCode) . ', ' . trim((string)$rawStateCodes)
                : $rawPrimaryStateCode;
            $this->state_code = null;
        } elseif ($rawPrimaryStateCode) {
            $this->state_code = $rawPrimaryStateCode;
        }

        if (is_string($rawStateCodes)) {
            $rawStateCodes = strtoupper($rawStateCodes);
            $this->attributes['state_codes'] = $rawStateCodes;

            if (trim($rawStateCodes) !== '' && !preg_match(self::USA_STATE_CODES_LIST_PATTERN, $rawStateCodes)) {
                if ($this->is_active === null) {
                    $this->is_active = (bool)$this->is_enabled;
                }

                return;
            }
        }

        $stateCodes = $this->normalizeStateCodes($this->attributes['state_codes'] ?? null);

        if ($rawPrimaryStateCode && strpbrk($rawPrimaryStateCode, ',;.') !== false) {
            $stateCodes = array_merge($this->normalizeStateCodes($rawPrimaryStateCode), $stateCodes);
        }

        if ($this->state_code && !in_array($this->state_code, $stateCodes, true)) {
            array_unshift($stateCodes, $this->state_code);
        }

        if (!$this->state_code && $stateCodes) {
            $this->state_code = $stateCodes[0];
        }

        $this->state_codes = implode(', ', array_values(array_unique($stateCodes)));

        if ($this->is_active === null) {
            $this->is_active = (bool)$this->is_enabled;
        }

        $mainGroup = self::taxMainGroupForCodes($this->taxGroupCodesList());
        $this->tax_main_group = $mainGroup;
        $this->tax_main_group_name = self::taxMainGroupOptions()[$mainGroup] ?? 'General';
    }

    public function stateCodesList(): array
    {
        return $this->normalizeStateCodes($this->attributes['state_codes'] ?? null);
    }

    public function getStateCodesDisplayAttribute(): string
    {
        return implode(', ', $this->stateCodesList());
    }

    public function getTaxGroupDisplayAttribute(): string
    {
        $codes = $this->taxGroupCodesList();

        if (count($codes) > 1) {
            return sprintf('%d %s tax groups', count($codes), strtolower($this->tax_main_group_display));
        }

        return trim(implode(' / ', array_filter([
            $this->tax_group_code,
            $this->tax_group_name,
        ])));
    }

    public function getTaxMainGroupAttribute(): string
    {
        $codes = $this->taxGroupCodesList();

        if ($codes) {
            return self::taxMainGroupForCodes($codes);
        }

        return (string)($this->attributes['tax_main_group'] ?? self::TAX_MAIN_GROUP_GENERAL);
    }

    public function getTaxMainGroupDisplayAttribute(): string
    {
        return $this->attributes['tax_main_group_name']
            ?? self::taxMainGroupOptions()[$this->tax_main_group]
            ?? 'General';
    }

    public function getJurisdictionDisplayAttribute(): string
    {
        if (!$this->jurisdiction_name && !$this->jurisdiction_code) {
            return $this->state_codes_display ?: (string)$this->state_code;
        }

        return trim(implode(' / ', array_filter([
            $this->jurisdiction_name,
            $this->jurisdiction_code,
        ])));
    }

    public function isSourceBacked(): bool
    {
        return trim((string)$this->source_url) !== ''
            || trim((string)$this->boundary_source_url) !== ''
            || (
                trim((string)$this->source_type) !== ''
                && strtoupper(trim((string)$this->source_type)) !== 'MANUAL'
            )
            || trim((string)$this->source_name) !== ''
            || trim((string)$this->parser_name) !== ''
            || trim((string)$this->source_hash) !== '';
    }

    public function setStateCodesAttribute($value): void
    {
        $this->attributes['state_codes'] = implode(', ', $this->normalizeStateCodes($value));
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
            ->flatMap(fn ($group) => self::taxGroupCodesByMainGroup()[$group] ?? [])
            ->unique()
            ->values()
            ->all();

        $query->where(function ($query) use ($groups, $codes) {
            $query->whereIn('tax_main_group', $groups);

            if ($codes) {
                $query->orWhereIn('tax_group_code', $codes)
                    ->orWhereHas('tax_group_code_rows', function ($query) use ($codes) {
                        $query->whereIn('tax_group_code', $codes);
                    });
            }

            if (in_array(self::TAX_MAIN_GROUP_GENERAL, $groups, true)) {
                $query->orWhereNull('tax_group_code');
            }
        });
    }

    public function scopeStateCodes($query, $states): void
    {
        $states = collect((array)$states)
            ->map(fn ($state) => strtoupper(trim((string)$state)))
            ->filter(fn ($state) => preg_match('/^[A-Z]{2}$/', $state))
            ->unique()
            ->values()
            ->all();

        if (!$states) {
            return;
        }

        $query->where(function ($query) use ($states) {
            foreach ($states as $state) {
                $query->orWhere('state_code', $state)
                    ->orWhereRaw(
                        "(',' || REPLACE(COALESCE(state_codes, ''), ' ', '') || ',') LIKE ?",
                        ['%,' . $state . ',%']
                    );
            }
        });
    }

    public static function availableStateCodeOptions(): array
    {
        $labels = self::usaStateOptions();

        return static::withDisabled()
            ->get(['state_code', 'state_codes'])
            ->flatMap(fn (self $tax) => $tax->stateCodesList())
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $code) => [$code => $labels[$code] ?? $code])
            ->all();
    }

    public static function availableTaxMainGroupOptions(): array
    {
        $labels = self::taxMainGroupOptions();

        return static::withDisabled()
            ->get(['tax_main_group', 'tax_group_code'])
            ->map(fn (self $tax) => $tax->tax_main_group)
            ->filter()
            ->unique()
            ->sortBy(fn (string $group) => $labels[$group] ?? $group)
            ->mapWithKeys(fn (string $group) => [$group => $labels[$group] ?? ucfirst($group)])
            ->all();
    }

    public function scopeApplyBackendListFilterScope($query, $scope): void
    {
        static::applyBackendListFilterScope($query, $scope);
    }

    public static function applyBackendListFilterScope($query, $scope): void
    {
        $scopeName = (string)$scope->scopeName;

        if (str_starts_with($scopeName, 'posmall_tax_state_')) {
            $query->stateCodes([strtoupper(substr($scopeName, strlen('posmall_tax_state_')))]);

            return;
        }

        if (str_starts_with($scopeName, 'posmall_tax_group_')) {
            $query->taxMainGroup([substr($scopeName, strlen('posmall_tax_group_'))]);
        }
    }

    public static function usaStateOptions(): array
    {
        return [
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
            'DC' => 'District of Columbia',
        ];
    }

    public static function taxMainGroupOptions(): array
    {
        return [
            self::TAX_MAIN_GROUP_PHYSICAL => 'Physical product',
            self::TAX_MAIN_GROUP_SERVICE => 'Service',
            self::TAX_MAIN_GROUP_VIRTUAL => 'Virtual product',
            self::TAX_MAIN_GROUP_GENERAL => 'General',
        ];
    }

    public static function taxMainGroupForCode(string $code): string
    {
        foreach (self::taxGroupCodesByMainGroup() as $group => $codes) {
            if (in_array($code, $codes, true)) {
                return $group;
            }
        }

        return self::TAX_MAIN_GROUP_GENERAL;
    }

    public static function taxMainGroupForCodes(array $codes): string
    {
        $groups = collect($codes)
            ->map(fn ($code) => self::taxMainGroupForCode((string)$code))
            ->unique()
            ->values()
            ->all();

        if (count($groups) === 1) {
            return $groups[0];
        }

        return in_array(self::TAX_MAIN_GROUP_GENERAL, $groups, true)
            ? self::TAX_MAIN_GROUP_GENERAL
            : (string)($groups[0] ?? self::TAX_MAIN_GROUP_GENERAL);
    }

    public static function taxGroupCodesByMainGroup(): array
    {
        return [
            self::TAX_MAIN_GROUP_PHYSICAL => [
                'PHYSICAL_TPP',
                'DIGITAL_ON_PHYSICAL_MEDIA',
                'PREWRITTEN_SOFTWARE_PHYSICAL',
                'CLOTHING_FOOTWEAR',
                'FOOD_GROCERY',
                'MEDICAL_DURABLE_EQUIPMENT',
            ],
            self::TAX_MAIN_GROUP_SERVICE => [
                'DIGITAL_AUTOMATED_SERVICE',
                'DIGITAL_AUDIOVISUAL_AUDIO_SERVICE',
                'HUMAN_PROFESSIONAL_SERVICE',
                'RETAIL_REPAIR_INSTALLATION_SERVICE',
                'REAL_PROPERTY_CONSTRUCTION_REPAIR_SERVICE',
                'LANDSCAPING_MAINTENANCE_SERVICE',
                'INFORMATION_TECHNOLOGY_SERVICE',
                'CUSTOM_WEBSITE_DEVELOPMENT_SERVICE',
                'CUSTOM_SOFTWARE_DEV',
                'CUSTOM_MODIFICATION_SEPARATE',
                'ADVERTISING_SERVICE',
                'TEMPORARY_STAFFING_SERVICE',
                'SECURITY_INVESTIGATION_SERVICE',
                'LIVE_PRESENTATION_SERVICE',
                'DATA_PROCESSING_SERVICE',
                'INFORMATION_SERVICE',
            ],
            self::TAX_MAIN_GROUP_VIRTUAL => [
                'DIGITAL_FILE_ONLY',
                'PREWRITTEN_SOFTWARE_ELECTRONIC',
                'SAAS_REMOTE_ACCESS',
            ],
            self::TAX_MAIN_GROUP_GENERAL => [
                'GIFT_CARD_STORED_VALUE',
                'SHIPPING_HANDLING',
                'EXEMPT_CUSTOMER_RESALE',
            ],
        ];
    }

    protected function normalizeStateCodes($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\s*[,;.]\s*/', $value);
        }

        if (!is_array($value)) {
            $value = $value ? [$value] : [];
        }

        return collect($value)
            ->map(fn ($state) => strtoupper(trim((string)$state)))
            ->filter(fn ($state) => preg_match('/^[A-Z]{2}$/', $state))
            ->unique()
            ->values()
            ->all();
    }

    public function taxGroupCodesList(): array
    {
        $rows = $this->relationLoaded('tax_group_code_rows')
            ? $this->tax_group_code_rows
            : ($this->exists ? $this->tax_group_code_rows()->get() : collect());

        return $this->normalizeTaxGroupCodes(array_merge(
            [$this->tax_group_code],
            $rows->pluck('tax_group_code')->all()
        ));
    }

    public function matchesTaxGroupCode(?string $code): bool
    {
        if (!$code) {
            return true;
        }

        return in_array(strtoupper(trim($code)), $this->taxGroupCodesList(), true);
    }

    protected function normalizeTaxGroupCodes($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : preg_split('/\s*[,;.]\s*/', $value);
        }

        if (!is_array($value)) {
            $value = $value ? [$value] : [];
        }

        return collect($value)
            ->map(fn ($code) => strtoupper(trim((string)$code)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get percentage decimal attribute.
     * @return float
     */
    public function getPercentageDecimalAttribute()
    {
        return (float)$this->percentage / 100;
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->name,
            $this->state_code,
            $this->tax_group_code,
        ]);

        return implode(' / ', $parts);
    }
}
