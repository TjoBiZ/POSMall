<?php

namespace KodZero\POSMall\Classes\Utils;

use KodZero\POSMall\Models\Currency;

interface Money
{
    public function format(?float $value, $product = null, ?Currency $currency = null): string;

    public function round($value, $decimals = 2): float;
}
