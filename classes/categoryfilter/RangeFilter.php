<?php

namespace KodZero\POSMall\Classes\CategoryFilter;

class RangeFilter extends Filter
{
    public $minValue;

    public $maxValue;

    public function __construct($property, array $values)
    {
        parent::__construct($property);
        $this->minValue = $this->numericValue($values[0] ?? null);
        $this->maxValue = $this->numericValue($values[1] ?? null);

        if ($this->minValue !== null && $this->maxValue !== null && $this->minValue > $this->maxValue) {
            [$this->minValue, $this->maxValue] = [$this->maxValue, $this->minValue];
        }
    }

    public function isValid(): bool
    {
        return $this->minValue !== null && $this->maxValue !== null;
    }

    public function values(): array
    {
        return [
            'min' => $this->minValue,
            'max' => $this->maxValue,
        ];
    }

    protected function numericValue($value): ?float
    {
        $value = trim((string)$value);

        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float)$value : null;
    }
}
