<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use October\Rain\Database\Builder;

class TaxListSortMapper
{
    private const DISPLAY_SORT_COLUMNS = [
        'state_codes_display',
        'jurisdiction_display',
        'tax_main_group_display',
        'tax_group_display',
    ];

    public function apply(Builder $query, string $sortColumn, string $sortDirection): void
    {
        if (!in_array($sortColumn, self::DISPLAY_SORT_COLUMNS, true)) {
            return;
        }

        $direction = strtolower($sortDirection) === 'desc' ? 'desc' : 'asc';

        $query->reorder();

        foreach ($this->expressionsFor($query, $sortColumn) as $expression) {
            $query->orderByRaw($expression . ' ' . $direction);
        }

        $query->orderByRaw($this->column($query, 'id') . ' asc');
    }

    private function expressionsFor(Builder $query, string $sortColumn): array
    {
        $stateCodes = $this->stateCodesExpression($query);

        return match ($sortColumn) {
            'state_codes_display' => [
                $stateCodes,
            ],
            'jurisdiction_display' => [
                'COALESCE(NULLIF(CONCAT_WS(\' / \', NULLIF('
                    . $this->column($query, 'jurisdiction_name')
                    . ', \'\'), NULLIF('
                    . $this->column($query, 'jurisdiction_code')
                    . ', \'\')), \'\'), ' . $stateCodes . ')',
            ],
            'tax_main_group_display' => [
                'COALESCE(NULLIF(' . $this->column($query, 'tax_main_group_name') . ', \'\'), '
                    . $this->column($query, 'tax_main_group') . ', \'General\')',
            ],
            'tax_group_display' => [
                'COALESCE(NULLIF(' . $this->column($query, 'tax_group_code') . ', \'\'), '
                    . 'NULLIF(' . $this->column($query, 'tax_group_name') . ', \'\'), '
                    . '(SELECT MIN(' . $this->relatedColumn($query, 'kodzero_posmall_tax_group_codes', 'tax_group_code') . ') '
                    . 'FROM ' . $this->table($query, 'kodzero_posmall_tax_group_codes')
                    . ' WHERE ' . $this->relatedColumn($query, 'kodzero_posmall_tax_group_codes', 'tax_id')
                    . ' = ' . $this->column($query, 'id') . '), '
                    . $this->column($query, 'tax_main_group') . ', \'\')',
            ],
            default => [],
        };
    }

    private function stateCodesExpression(Builder $query): string
    {
        return 'COALESCE(NULLIF(' . $this->column($query, 'state_codes') . ', \'\'), '
            . $this->column($query, 'state_code') . ', \'\')';
    }

    private function column(Builder $query, string $column): string
    {
        $model = $query->getModel();

        return $model->getConnection()
            ->getQueryGrammar()
            ->wrap($model->getTable() . '.' . $column);
    }

    private function relatedColumn(Builder $query, string $table, string $column): string
    {
        return $query->getModel()
            ->getConnection()
            ->getQueryGrammar()
            ->wrap($table . '.' . $column);
    }

    private function table(Builder $query, string $table): string
    {
        return $query->getModel()
            ->getConnection()
            ->getQueryGrammar()
            ->wrapTable($table);
    }
}
