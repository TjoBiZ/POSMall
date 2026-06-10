<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes\Parsers;

interface UsaTaxSourceParser
{
    public function parse(array $source, string $stateCode): array;
}
