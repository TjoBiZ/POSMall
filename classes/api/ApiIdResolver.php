<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

use Hashids\Hashids as Hasher;
use October\Rain\Exception\ValidationException;

class ApiIdResolver
{
    public function modelId(string|int $value, string $prefix = ''): int
    {
        $value = trim((string)$value);
        $prefix = trim($prefix);

        if ($prefix !== '' && str_starts_with($value, $prefix . '-')) {
            $value = substr($value, strlen($prefix) + 1);
        }

        if (ctype_digit($value)) {
            return (int)$value;
        }

        $decoded = app(Hasher::class)->decode($value);

        if (count($decoded) !== 1) {
            throw new ValidationException(['id' => 'Invalid POSMall hash id.']);
        }

        return (int)$decoded[0];
    }
}
