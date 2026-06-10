<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Carbon\Carbon;
use Model;
use October\Rain\Database\Traits\Validation;
use October\Rain\Exception\ValidationException;

class ApiToken extends Model
{
    use Validation;

    public const TABLE = 'kodzero_posmall_api_tokens';
    public const TOKEN_PREFIX = 'posmall_';
    public const TRUSTED_WILDCARD_SCOPE = '*';

    public $table = self::TABLE;

    public $rules = [
        'name' => 'required',
        'token_hash' => 'required|size:64',
    ];

    public $fillable = [
        'name',
        'scopes',
        'allowed_origins',
        'allowed_customer_ids',
        'allowed_vendor_ids',
        'allowed_channel_ids',
        'allowed_warehouse_ids',
        'rate_limit_per_minute',
        'expires_at',
        'revoked_at',
    ];

    public $jsonable = [
        'scopes',
        'allowed_origins',
        'allowed_customer_ids',
        'allowed_vendor_ids',
        'allowed_channel_ids',
        'allowed_warehouse_ids',
    ];

    public $hidden = [
        'token_hash',
    ];

    protected $dates = [
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_at',
        'updated_at',
    ];

    public ?string $plain_token = null;

    public static function scopeTree(): array
    {
        return [
            [
                'label' => 'Catalog and discovery',
                'description' => 'Public catalog data, product discovery, filters, vendors, channels and warehouse stock.',
                'items' => [
                    [
                        'scope' => 'catalog:read',
                        'label' => 'Read catalog',
                        'verb' => 'Read',
                        'description' => 'Products, categories, brands, properties, vendors, channels, warehouses and stock.',
                        'depends' => 'Needed before most cart, checkout, review and Favorite List workflows.',
                    ],
                    [
                        'scope' => 'reviews:write',
                        'label' => 'Create reviews',
                        'verb' => 'Write',
                        'description' => 'Create product reviews for an allowed customer.',
                        'depends' => 'Use with catalog read and customer allow-lists for customer-facing integrations.',
                    ],
                ],
            ],
            [
                'label' => 'Cart and checkout',
                'description' => 'Customer cart mutation, shipping/payment selection and order creation.',
                'items' => [
                    [
                        'scope' => 'cart:read',
                        'label' => 'Read cart',
                        'verb' => 'Read',
                        'description' => 'Read customer cart contents, totals, shipping methods and payment methods.',
                        'depends' => 'Narrow customer IDs for customer-specific tokens.',
                    ],
                    [
                        'scope' => 'cart:write',
                        'label' => 'Change cart',
                        'verb' => 'Write',
                        'description' => 'Add, update and remove cart items, discounts, address, shipping and payment method.',
                        'depends' => 'Usually paired with catalog read and customer allow-lists.',
                    ],
                    [
                        'scope' => 'checkout:write',
                        'label' => 'Create orders',
                        'verb' => 'Write',
                        'description' => 'Convert a prepared cart into an order and optionally create a website payment link.',
                        'depends' => 'Requires a valid customer/cart workflow; pair with cart read/write for full checkout.',
                    ],
                ],
            ],
            [
                'label' => 'Customers and orders',
                'description' => 'Customer profile, address and order history access.',
                'items' => [
                    [
                        'scope' => 'customers:read',
                        'label' => 'Read customers',
                        'verb' => 'Read',
                        'description' => 'Read an allowed customer profile and addresses.',
                        'depends' => 'Use customer allow-lists unless the integration is trusted server-to-server.',
                    ],
                    [
                        'scope' => 'customers:write',
                        'label' => 'Change customer addresses',
                        'verb' => 'Write',
                        'description' => 'Create and update customer addresses and delivery notes.',
                        'depends' => 'Use customer allow-lists and validation tests for public client flows.',
                    ],
                    [
                        'scope' => 'orders:read',
                        'label' => 'Read orders',
                        'verb' => 'Read',
                        'description' => 'Read order lists, order detail and order status for an allowed customer/context.',
                        'depends' => 'Orders expose customer and purchase data; restrict customer/vendor/channel context.',
                    ],
                ],
            ],
            [
                'label' => 'Favorite Lists',
                'description' => 'Customer Favorite List read/write flows.',
                'items' => [
                    [
                        'scope' => 'favorites:read',
                        'label' => 'Read Favorite Lists',
                        'verb' => 'Read',
                        'description' => 'Read Favorite Lists for an allowed customer.',
                        'depends' => 'Use customer allow-lists for customer-facing integrations.',
                    ],
                    [
                        'scope' => 'favorites:write',
                        'label' => 'Change Favorite Lists',
                        'verb' => 'Write',
                        'description' => 'Create Favorite Lists and add or remove Favorite List items.',
                        'depends' => 'Usually paired with catalog read and customer allow-lists.',
                    ],
                ],
            ],
            [
                'label' => 'Trusted internal access',
                'description' => 'Use only for fully trusted server-side integrations.',
                'items' => [
                    [
                        'scope' => self::TRUSTED_WILDCARD_SCOPE,
                        'label' => 'Full API access',
                        'verb' => 'All',
                        'description' => 'Bypasses individual scope checks. Context allow-lists still narrow explicit contexts.',
                        'depends' => 'Do not use in browser code or untrusted third-party integrations.',
                        'danger' => true,
                    ],
                ],
            ],
        ];
    }

    public static function knownScopes(): array
    {
        return collect(self::scopeTree())
            ->flatMap(fn (array $group) => $group['items'] ?? [])
            ->pluck('scope')
            ->values()
            ->all();
    }

    public function beforeValidate(): void
    {
        $plainToken = trim((string)$this->plain_token);

        if ($plainToken !== '') {
            $this->setPlainToken($plainToken);
        }

        $unknownScopes = array_values(array_diff(
            $this->normalizeList($this->scopes ?: ['catalog:read']),
            self::knownScopes()
        ));

        if ($unknownScopes !== []) {
            throw new ValidationException([
                'scopes' => 'Unknown POSMall API scope: ' . implode(', ', $unknownScopes),
            ]);
        }
    }

    public function beforeSave(): void
    {
        $this->scopes = $this->normalizeList($this->scopes ?: ['catalog:read']);
        $this->allowed_origins = $this->normalizeList($this->allowed_origins ?: []);
        $this->allowed_customer_ids = $this->normalizeIds($this->allowed_customer_ids ?: []);
        $this->allowed_vendor_ids = $this->normalizeIds($this->allowed_vendor_ids ?: []);
        $this->allowed_channel_ids = $this->normalizeIds($this->allowed_channel_ids ?: []);
        $this->allowed_warehouse_ids = $this->normalizeIds($this->allowed_warehouse_ids ?: []);
    }

    public function filterFields($fields, $context = null): void
    {
        foreach (['allowed_origins'] as $field) {
            if (isset($fields->{$field})) {
                $fields->{$field}->value = implode("\n", $this->normalizeList($this->{$field} ?: []));
            }
        }

        foreach (['allowed_customer_ids', 'allowed_vendor_ids', 'allowed_channel_ids', 'allowed_warehouse_ids'] as $field) {
            if (isset($fields->{$field})) {
                $fields->{$field}->value = implode("\n", $this->normalizeIds($this->{$field} ?: []));
            }
        }
    }

    public static function generatePlainToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(32));
    }

    public static function hashPlainToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    public static function findUsableByPlainToken(string $token): ?self
    {
        $hash = self::hashPlainToken($token);

        return self::whereNull('revoked_at')
            ->where('token_hash', $hash)
            ->first();
    }

    public function setPlainToken(string $token): void
    {
        $this->token_hash = self::hashPlainToken($token);
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || Carbon::parse($this->expires_at)->isFuture();
    }

    public function hasScope(string $scope): bool
    {
        $scopes = collect($this->scopes ?: [])->map(fn ($value) => (string)$value);

        return $scopes->contains(self::TRUSTED_WILDCARD_SCOPE) || $scopes->contains($scope);
    }

    public function hasAnyScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->hasScope((string)$scope)) {
                return true;
            }
        }

        return false;
    }

    public function allowsOrigin(?string $origin): bool
    {
        $origins = array_filter(array_map('trim', (array)($this->allowed_origins ?: [])));

        if ($origins === []) {
            return true;
        }

        if (!is_string($origin) || trim($origin) === '') {
            return false;
        }

        foreach ($origins as $allowed) {
            if (hash_equals($allowed, trim($origin))) {
                return true;
            }
        }

        return false;
    }

    public function allowsCustomerId(?int $id): bool
    {
        return $this->allowsId('allowed_customer_ids', $id);
    }

    public function allowsVendorId(?int $id): bool
    {
        return $this->allowsId('allowed_vendor_ids', $id);
    }

    public function allowsChannelId(?int $id): bool
    {
        return $this->allowsId('allowed_channel_ids', $id);
    }

    public function allowsWarehouseId(?int $id): bool
    {
        return $this->allowsId('allowed_warehouse_ids', $id);
    }

    public function markUsed(): void
    {
        $this->last_used_at = now();
        $this->saveQuietly();
    }

    public function getScopesListAttribute(): string
    {
        return implode(', ', $this->normalizeList($this->scopes ?: []));
    }

    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        return collect((array)$value)
            ->map(fn ($item) => is_array($item) ? ($item['value'] ?? '') : $item)
            ->map(fn ($item) => trim((string)$item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        return collect((array)$value)
            ->map(fn ($item) => is_array($item) ? ($item['value'] ?? '') : $item)
            ->map(fn ($item) => (int)$item)
            ->filter(fn (int $item) => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function allowsId(string $attribute, ?int $id): bool
    {
        $ids = $this->normalizeIds($this->{$attribute} ?: []);

        if ($ids === []) {
            return true;
        }

        return $id !== null && in_array($id, $ids, true);
    }
}
