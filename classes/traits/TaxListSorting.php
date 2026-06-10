<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Traits;

use KodZero\POSMall\Classes\Taxes\TaxListSortMapper;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\Tax;

trait TaxListSorting
{
    public function relationExtendViewListWidget($widget, $field, $model)
    {
        $this->bindTaxListSortMapper($widget, $field);
        $this->bindTaxRelationMainGroupScope($widget, $field, $model);
    }

    public function relationExtendManageListWidget($widget, $field, $model)
    {
        $this->bindTaxListSortMapper($widget, $field);
        $this->bindTaxRelationMainGroupScope($widget, $field, $model);
    }

    protected function bindTaxListSortMapper($widget, $field): void
    {
        if ($field !== 'taxes') {
            return;
        }

        $widget->bindEvent('list.extendSortColumn', function ($query, $sortColumn, $sortDirection) {
            app(TaxListSortMapper::class)->apply($query, $sortColumn, $sortDirection);
        });
    }

    protected function bindTaxRelationMainGroupScope($widget, $field, $model): void
    {
        if ($field !== 'taxes') {
            return;
        }

        $groups = $this->taxRelationMainGroupsFor($model);

        if (!$groups) {
            return;
        }

        $widget->bindEvent('list.extendQueryBefore', function ($query) use ($groups) {
            $query->taxMainGroup($groups);
        });
    }

    protected function taxRelationMainGroupsFor($model): ?array
    {
        if ($model instanceof Service) {
            return [
                Tax::TAX_MAIN_GROUP_SERVICE,
                Tax::TAX_MAIN_GROUP_GENERAL,
            ];
        }

        if ($model instanceof Product) {
            return [
                $model->is_virtual ? Tax::TAX_MAIN_GROUP_VIRTUAL : Tax::TAX_MAIN_GROUP_PHYSICAL,
                Tax::TAX_MAIN_GROUP_GENERAL,
            ];
        }

        return null;
    }
}
