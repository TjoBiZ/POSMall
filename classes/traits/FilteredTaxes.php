<?php

namespace KodZero\POSMall\Classes\Traits;

use Event;
use Illuminate\Support\Collection;
use KodZero\POSMall\Classes\User\Auth;
use KodZero\POSMall\Classes\Taxes\UsaTaxResolver;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Tax;

/**
 * This trait is used to filter a Collection of taxes based
 * on a provided shipping destination country.
 */

trait FilteredTaxes
{
    public $countryId;
    public $stateId;
    public $stateCode;
    public $zipCode;

    /**
     * Filter a tax collection based on the shipping destination country.
     *
     * @param mixed $taxes
     * @param mixed $ignoreDefaults
     * @return Collection
     */
    public function getFilteredTaxes($taxes, $ignoreDefaults = false)
    {
        if (!$taxes instanceof Collection) {
            $taxes = Collection::wrap($taxes);
        }

        // Don't filter anything and don't use the default tax if no taxes were passed in.
        if ($taxes->count() === 0) {
            return $taxes;
        }

        $this->countryId = $this->getCartCountryId();
        $this->stateId = $this->getCartStateId();
        $this->stateCode = $this->getCartStateCode();
        $this->zipCode = $this->getCartZip();

        Event::fire('posmall.cart.setCountry', $this);

        // If the shipping destination is not yet known, return the default tax.
        if ($this->countryId === null) {
            // For shipping and payment methods, we use the input taxes as default (as long as they don't have a country restriction).
            $globalTaxes = $taxes->filter(fn ($tax) => $tax->countries->count() === 0);

            if ($ignoreDefaults && $globalTaxes->count() > 0) {
                return $globalTaxes;
            }

            return $this->filterUsaDestinationTaxes(Tax::defaultTaxes());
        }

        // If the shipping destination is known, return all taxes that have
        // no country attached (valid for all countries) and all taxes that have
        // the shipping country attached.
        $filtered = $taxes->filter(function ($tax) {
            $matchesCountry = $tax->countries->count() === 0
                || $tax->countries->pluck('id')->search($this->countryId) !== false;
            $matchesState = $tax->states->count() === 0
                || $tax->states->pluck('id')->search($this->stateId) !== false;

            return $matchesCountry && $matchesState;
        });

        return $this->filterUsaDestinationTaxes($filtered);
    }

    protected function filterUsaDestinationTaxes(Collection $taxes): Collection
    {
        $usaTaxes = $taxes->filter(fn (Tax $tax) => $this->isUsaTax($tax));

        if ($usaTaxes->isEmpty()) {
            return $taxes;
        }

        $nonUsaTaxes = $taxes->reject(fn (Tax $tax) => $this->isUsaTax($tax));
        $stateCode = $this->stateCode ? strtoupper((string)$this->stateCode) : null;
        $resolver = app(UsaTaxResolver::class);

        if ($stateCode) {
            $usaTaxes = $usaTaxes->filter(fn (Tax $tax) => in_array($stateCode, $this->usaTaxStateCodes($tax), true));
        }

        if ($usaTaxes->isEmpty()) {
            return $nonUsaTaxes;
        }

        $zip = $resolver->normalizeZip($this->zipCode);
        if ($zip) {
            $zipTaxes = $usaTaxes->filter(fn (Tax $tax) => $tax->zip_code_ranges && $resolver->zipMatchesRanges($zip, (string)$tax->zip_code_ranges));

            if ($zipTaxes->isNotEmpty()) {
                return $nonUsaTaxes->concat($this->bestUsaZipTaxes($zipTaxes))->values();
            }
        }

        $baseTaxes = $usaTaxes->filter(fn (Tax $tax) => !$tax->zip_code_ranges && !$tax->jurisdiction_code);

        return $nonUsaTaxes->concat($baseTaxes->isNotEmpty() ? $baseTaxes : $usaTaxes)->values();
    }

    protected function bestUsaZipTaxes(Collection $taxes): Collection
    {
        return $taxes
            ->groupBy(fn (Tax $tax) => $tax->tax_main_group ?: $tax->tax_group_code ?: (string)$tax->id)
            ->map(function (Collection $group) {
                return $group
                    ->sort(function (Tax $left, Tax $right) {
                        $leftRate = (float)($left->rate_percent ?? $left->percentage);
                        $rightRate = (float)($right->rate_percent ?? $right->percentage);

                        if ($leftRate !== $rightRate) {
                            return $rightRate <=> $leftRate;
                        }

                        return (int)$left->id <=> (int)$right->id;
                    })
                    ->first();
            })
            ->filter()
            ->values();
    }

    protected function isUsaTax(Tax $tax): bool
    {
        return (bool)($tax->state_code || $tax->state_codes || $tax->tax_group_code);
    }

    protected function usaTaxStateCodes(Tax $tax): array
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

    /**
     * Return the current shipping destination country id.
     * If the destination is currently unknown, null is returned.
     */
    public function getCartCountryId()
    {
        $cart = Cart::byUser(Auth::user());

        if (!$cart) {
            return null;
        } else {
            return optional($cart->shipping_address)->country_id ?? $cart->getFallbackShippingCountryId();
        }
    }

    public function getCartStateId()
    {
        $cart = Cart::byUser(Auth::user());

        if (!$cart) {
            return null;
        } else {
            return optional($cart->shipping_address)->state_id ?? $cart->getFallbackShippingStateId();
        }
    }

    public function getCartStateCode()
    {
        $cart = Cart::byUser(Auth::user());

        if (!$cart || !$cart->shipping_address) {
            return null;
        }

        return optional($cart->shipping_address)->state_code;
    }

    public function getCartZip()
    {
        $cart = Cart::byUser(Auth::user());

        if (!$cart || !$cart->shipping_address) {
            return null;
        }

        return $cart->shipping_address->zip;
    }
}
