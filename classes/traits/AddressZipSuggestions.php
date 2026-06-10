<?php

declare(strict_types=1);

namespace KodZero\POSMall\Classes\Traits;

use Illuminate\Support\Facades\RateLimiter;
use KodZero\POSMall\Classes\Taxes\UsaAddressZipSuggester;
use KodZero\POSMall\Models\GeneralSettings;

trait AddressZipSuggestions
{
    private const ADDRESS_ZIP_SUGGESTION_RATE_LIMIT = 60;

    /**
     * Browser-visible Google Maps Platform API key for Places autocomplete.
     *
     * @var string
     */
    public $googlePlacesBrowserApiKey = '';

    /**
     * Enables the optional Google Places street-address prediction layer.
     *
     * @var bool
     */
    public $googlePlacesAddressAutocompleteEnabled = false;

    public function onSuggestAddressZip(): array
    {
        if (!$this->allowsAddressZipSuggestionRequest()) {
            return ['suggestions' => []];
        }

        return app(UsaAddressZipSuggester::class)->suggest([
            'country_id' => post('country_id'),
            'state_id' => post('state_id'),
            'state_code' => post('state_code'),
            'lines' => post('lines'),
            'city' => post('city'),
            'zip' => post('zip'),
        ]);
    }

    protected function prepareAddressAutocompleteSettings(): void
    {
        $key = trim((string)$this->addressAutocompleteSetting('google_places_browser_api_key', ''));
        $enabled = filter_var(
            $this->addressAutocompleteSetting('google_places_address_autocomplete_enabled', false),
            FILTER_VALIDATE_BOOLEAN
        ) && $key !== '';

        $this->googlePlacesBrowserApiKey = $enabled ? $key : '';
        $this->googlePlacesAddressAutocompleteEnabled = $enabled;
        $this->setVar('googlePlacesBrowserApiKey', $enabled ? $key : '');
        $this->setVar('googlePlacesAddressAutocompleteEnabled', $enabled);
    }

    private function addressAutocompleteSetting(string $key, $default = null)
    {
        return GeneralSettings::getUncached($key, $default);
    }

    private function allowsAddressZipSuggestionRequest(): bool
    {
        $key = 'kodzero_posmall.address_zip_suggestions:' . sha1((string)request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::ADDRESS_ZIP_SUGGESTION_RATE_LIMIT)) {
            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
