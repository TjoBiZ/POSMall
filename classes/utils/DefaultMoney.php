<?php

namespace KodZero\POSMall\Classes\Utils;

use KodZero\POSMall\Models\Currency;

class DefaultMoney implements Money
{
    public function format(?float $value, $product = null, ?Currency $currency = null): string
    {
        $currency ??= Currency::activeCurrency();

        $currencyDecimals = (int)($currency['decimals'] ?? 2);
        $value    = app(Money::class)->round($value, $currencyDecimals);
        $integers = floor($value);
        $decimals = round(($value - $integers) * 100, 0);
        $format   = (string)$currency['format'];

        if ($legacy = $this->renderLegacyPrintfFormat($format, $value, $currencyDecimals)) {
            return $legacy;
        }

        return $this->render($format, [
            'price'    => $value,
            'integers' => $integers,
            'decimals' => str_pad($decimals, 2, '0', STR_PAD_LEFT),
            'currency' => [
                'code'     => (string)($currency['code'] ?? ''),
                'symbol'   => (string)($currency['symbol'] ?? ''),
                'decimals' => $currencyDecimals,
            ],
        ]);
    }

    public function round($value, $decimals = 2): float
    {
        return round($value / 100, $decimals ?? 2);
    }

    protected function render(string $contents, array $vars): string
    {
        $output = '';
        $offset = 0;

        if (!preg_match_all('/\{\{\s*(.*?)\s*\}\}/s', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return e($contents);
        }

        foreach ($matches[0] as $index => $match) {
            [$token, $position] = $match;
            $output .= e(substr($contents, $offset, $position - $offset));
            $output .= e($this->renderExpression(trim($matches[1][$index][0]), $vars));
            $offset = $position + strlen($token);
        }

        return $output . e(substr($contents, $offset));
    }

    protected function renderExpression(string $expression, array $vars): string
    {
        $tokens = [
            'price'           => (string)$vars['price'],
            'integers'        => (string)$vars['integers'],
            'decimals'        => (string)$vars['decimals'],
            'currency.code'   => (string)$vars['currency']['code'],
            'currency.symbol' => (string)$vars['currency']['symbol'],
        ];

        if (array_key_exists($expression, $tokens)) {
            return $tokens[$expression];
        }

        if (preg_match('/^price\s*\|\s*number_format\s*\((.*)\)$/s', $expression, $matches)) {
            return $this->renderNumberFormat($matches[1], $vars);
        }

        return '';
    }

    protected function renderNumberFormat(string $arguments, array $vars): string
    {
        $pattern = '/^\s*(currency\.decimals|\d+)\s*(?:,\s*([\'"])(.*?)\2\s*(?:,\s*([\'"])(.*?)\4\s*)?)?$/s';

        if (!preg_match($pattern, $arguments, $matches)) {
            return (string)$vars['price'];
        }

        $decimals = $matches[1] === 'currency.decimals'
            ? (int)$vars['currency']['decimals']
            : (int)$matches[1];
        $decimalPoint = $matches[3] ?? '.';
        $thousandsSeparator = $matches[5] ?? ',';

        return number_format((float)$vars['price'], $decimals, $decimalPoint, $thousandsSeparator);
    }

    protected function renderLegacyPrintfFormat(string $format, float $value, int $decimals): ?string
    {
        if (str_contains($format, '{{') || str_contains($format, '{%')) {
            return null;
        }

        if (!preg_match('/%(?:\d+\$)?[bcdeEfFgGosuxX]/', $format)) {
            return null;
        }

        try {
            return sprintf($format, number_format($value, $decimals, '.', ','));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
