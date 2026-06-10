<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Illuminate\Http\Request;
use KodZero\POSMall\Models\ApiToken;
use KodZero\POSMall\Models\Channel;
use KodZero\POSMall\Models\Vendor;
use KodZero\POSMall\Models\Warehouse;

class CommerceContext
{
    public function __construct(
        public readonly ApiToken $token,
        public readonly ?Vendor $vendor = null,
        public readonly ?Channel $channel = null,
        public readonly ?Warehouse $warehouse = null,
        public readonly bool $vendorExplicit = false,
        public readonly bool $channelExplicit = false,
        public readonly bool $warehouseExplicit = false
    ) {
    }

    public static function default(ApiToken $token): self
    {
        return new self(
            $token,
            Vendor::where('is_default', true)->where('is_active', true)->first(),
            Channel::where('is_default', true)->where('is_active', true)->first(),
            Warehouse::where('is_default', true)->where('is_active', true)->first()
        );
    }

    public static function fromRequest(ApiToken $token, Request $request): self|JsonResponseMarker
    {
        $vendorValue = $request->headers->get('X-POSMall-Vendor') ?: $request->query('vendor');
        $channelValue = $request->headers->get('X-POSMall-Channel') ?: $request->query('channel');
        $warehouseValue = $request->headers->get('X-POSMall-Warehouse') ?: $request->query('warehouse');

        $vendor = self::resolve(Vendor::class, $vendorValue);
        $channel = self::resolve(Channel::class, $channelValue);
        $warehouse = self::resolve(Warehouse::class, $warehouseValue);

        foreach ([$vendor, $channel, $warehouse] as $resolved) {
            if ($resolved instanceof JsonResponseMarker) {
                return $resolved;
            }
        }

        $default = self::default($token);
        $context = new self(
            $token,
            $vendor ?: $default->vendor,
            $channel ?: $default->channel,
            $warehouse ?: $default->warehouse,
            trim((string)$vendorValue) !== '',
            trim((string)$channelValue) !== '',
            trim((string)$warehouseValue) !== ''
        );

        if (!$token->allowsVendorId(optional($context->vendor)->id)) {
            return JsonResponseMarker::error('context_not_allowed', 'The requested POSMall vendor context is not allowed for this token.', 403);
        }

        if (!$token->allowsChannelId(optional($context->channel)->id)) {
            return JsonResponseMarker::error('context_not_allowed', 'The requested POSMall channel context is not allowed for this token.', 403);
        }

        if (!$token->allowsWarehouseId(optional($context->warehouse)->id)) {
            return JsonResponseMarker::error('context_not_allowed', 'The requested POSMall warehouse context is not allowed for this token.', 403);
        }

        return $context;
    }

    public function orderAttributes(): array
    {
        return array_filter([
            'api_source' => 'api',
            'api_token_id' => $this->token->id,
            'vendor_id' => optional($this->vendor)->id,
            'channel_id' => optional($this->channel)->id,
            'warehouse_id' => optional($this->warehouse)->id,
        ], fn ($value) => $value !== null);
    }

    private static function resolve(string $modelClass, mixed $value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $query = $modelClass::where('is_active', true);
        $record = ctype_digit($value)
            ? $query->whereKey((int)$value)->first()
            : $query->where('slug', $value)->first();

        if ($record) {
            return $record;
        }

        return JsonResponseMarker::error('invalid_commerce_context', 'Requested POSMall commerce context is not available.', 422);
    }
}
