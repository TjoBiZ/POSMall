<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Api;

class JsonResponseMarker
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status,
        public readonly array $meta = []
    ) {
    }

    public static function error(string $code, string $message, int $status, array $meta = []): self
    {
        return new self($code, $message, $status, $meta);
    }
}
