<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Taxes;

use KodZero\POSMall\Models\Category;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\Service;
use KodZero\POSMall\Models\Tax;

class UsaTaxResolver
{
    public function resolveForProduct(Product $product): ?Tax
    {
        $productTax = $this->firstActiveTax($product->taxes);

        if ($productTax) {
            return $productTax;
        }

        $categories = $product->categories()->with('taxes')->get();
        $subcategoryTax = $categories
            ->filter(fn (Category $category) => (bool)$category->parent_id)
            ->map(fn (Category $category) => $this->firstActiveTax($category->taxes))
            ->filter()
            ->first();

        if ($subcategoryTax) {
            return $subcategoryTax;
        }

        $categoryTax = $categories
            ->filter(fn (Category $category) => !$category->parent_id)
            ->map(fn (Category $category) => $this->firstActiveTax($category->taxes))
            ->filter()
            ->first();

        return $categoryTax ?: $this->defaultTax();
    }

    public function resolveForService(Service $service): ?Tax
    {
        return $this->firstActiveTax($service->taxes) ?: $this->defaultTax();
    }

    public function defaultTax(): ?Tax
    {
        $configured = (int)GeneralSettings::get('usa_default_tax_id');

        if ($configured > 0) {
            $tax = Tax::where('id', $configured)
                ->where('is_enabled', true)
                ->where('is_active', true)
                ->first();

            if ($tax) {
                return $tax;
            }
        }

        return Tax::where('is_default', true)
            ->where('is_enabled', true)
            ->where('is_active', true)
            ->first();
    }

    public function resolveByZip(?string $stateCode, ?string $zipCode, ?string $taxGroupCode = null): ?Tax
    {
        $stateCode = $stateCode ? strtoupper($stateCode) : null;
        $rawZipCode = $zipCode;
        $zipCode = $this->normalizeZip($zipCode);

        if (!$stateCode) {
            return null;
        }

        $normalizedTax = app(UsaTaxRegionRows::class)->findTax($stateCode, $rawZipCode, $taxGroupCode);

        if ($normalizedTax) {
            return $normalizedTax;
        }

        $query = Tax::with('tax_group_code_rows')
            ->where('is_enabled', true)
            ->where('is_active', true)
            ->when($taxGroupCode, function ($query) use ($taxGroupCode) {
                $taxGroupCode = strtoupper(trim($taxGroupCode));

                $query->where(function ($query) use ($taxGroupCode) {
                    $query->where('tax_group_code', $taxGroupCode)
                        ->orWhereHas('tax_group_code_rows', function ($query) use ($taxGroupCode) {
                            $query->where('tax_group_code', $taxGroupCode);
                        });
                });
            });

        $taxes = $query->get()
            ->filter(fn (Tax $tax) => in_array($stateCode, $this->taxStateCodes($tax), true));

        if ($taxGroupCode) {
            $taxes = $taxes->filter(fn (Tax $tax) => $tax->matchesTaxGroupCode($taxGroupCode));
        }

        if ($zipCode) {
            $zipTax = $taxes
                ->filter(fn (Tax $tax) => $tax->zip_code_ranges && $this->zipMatchesRanges($zipCode, (string)$tax->zip_code_ranges))
                ->sortByDesc(fn (Tax $tax) => (float)($tax->rate_percent ?? $tax->percentage))
                ->first();

            if ($zipTax) {
                return $zipTax;
            }
        }

        return $taxes
            ->filter(fn (Tax $tax) => !$tax->zip_code_ranges && !$tax->jurisdiction_code)
            ->sortByDesc(fn (Tax $tax) => (float)($tax->rate_percent ?? $tax->percentage))
            ->first();
    }

    public function zipMatchesRanges(?string $zipCode, ?string $ranges): bool
    {
        $zipCode = $this->normalizeZip($zipCode);

        if (!$zipCode || !$ranges) {
            return false;
        }

        $zip = (int)$zipCode;

        foreach (preg_split('/\s*,\s*/', $ranges) ?: [] as $part) {
            $part = trim($part);

            if (preg_match('/^(\d{5})\s*-\s*(\d{5})$/', $part, $matches)) {
                $from = (int)$matches[1];
                $to = (int)$matches[2];

                if ($zip >= min($from, $to) && $zip <= max($from, $to)) {
                    return true;
                }
            }

            if (preg_match('/^\d{5}$/', $part) && $zipCode === $part) {
                return true;
            }
        }

        return false;
    }

    public function canSellToState($entity, ?string $stateCode): bool
    {
        if (!$stateCode) {
            return true;
        }

        $stateCode = strtoupper($stateCode);

        if (GeneralSettings::get('usa_sell_only_to_tax_states') && !$this->isGloballyAllowedState($stateCode)) {
            return false;
        }

        if (!$entity || !$entity->sell_only_to_tax_states) {
            return true;
        }

        return in_array($stateCode, $this->allowedStateCodes($entity), true);
    }

    public function allowedStateCodes($entity): array
    {
        if (!$entity) {
            return [];
        }

        try {
            $taxes = $entity->taxes;
        } catch (\Throwable $e) {
            return [];
        }

        return collect($taxes)
            ->filter(fn (Tax $tax) => (bool)$tax->is_enabled)
            ->flatMap(fn (Tax $tax) => $this->taxStateCodes($tax))
            ->unique()
            ->values()
            ->all();
    }

    protected function isGloballyAllowedState(string $stateCode): bool
    {
        return Tax::where('is_enabled', true)
            ->where('is_active', true)
            ->get()
            ->contains(fn (Tax $tax) => in_array($stateCode, $this->taxStateCodes($tax), true));
    }

    protected function taxStateCodes(Tax $tax): array
    {
        $states = $tax->stateCodesList();

        if ($tax->state_code) {
            $states[] = $tax->state_code;
        }

        return collect($states)
            ->map(fn ($state) => strtoupper((string)$state))
            ->filter(fn ($state) => preg_match('/^[A-Z]{2}$/', $state))
            ->unique()
            ->values()
            ->all();
    }

    public function normalizeZip(?string $zipCode): ?string
    {
        if (!$zipCode) {
            return null;
        }

        preg_match('/\d{5}/', $zipCode, $matches);

        return $matches[0] ?? null;
    }

    protected function firstActiveTax($taxes): ?Tax
    {
        return collect($taxes)->first(function (Tax $tax) {
            return (bool)$tax->is_enabled && (bool)($tax->is_active ?? true);
        });
    }
}
