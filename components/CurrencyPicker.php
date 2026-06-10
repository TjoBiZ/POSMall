<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use October\Rain\Router\Router as RainRouter;
use KodZero\POSMall\Models\Currency;

/**
 * The CurrencyPicker allows the user to select a currenty.
 */
class CurrencyPicker extends POSMallComponent
{
    private const QUERY_KEY = 'posmall_currency';

    /**
     * All available currencies.
     *
     * @var Collection
     */
    public $currencies;

    /**
     * The currently active currency.
     *
     * @var Currency
     */
    public $activeCurrency;

    /**
     * Whether the selector should be rendered.
     *
     * @var bool
     */
    public $showPicker = false;

    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.currencyPicker.details.name',
            'description' => 'kodzero.posmall::lang.components.currencyPicker.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        return [];
    }

    /**
     * The component is executed.
     *
     * @return void
     */
    public function onRun()
    {
        if ($redirect = $this->handleGetCurrencySwitch()) {
            return $redirect;
        }

        $currencies = Currency::getAll()
            ->where('is_enabled', true)
            ->sortBy('sort_order')
            ->values();

        $this->setVar('currencies', $currencies);
        $this->setVar('activeCurrency', Currency::activeCurrency());
        $this->setVar('showPicker', $currencies->count() > 1);
    }

    /**
     * The user selected a different currency.
     *
     * @return RedirectResponse
     */
    public function onSwitchCurrency()
    {
        if (! $currency = post('currency')) {
            return;
        }

        $currency = $this->findEnabledCurrency($currency);
        if (!$currency) {
            return;
        }

        Currency::setActiveCurrency($currency);

        $pageUrl = $this->getUrl();

        // preserve the query string, if it exists
        $query   = http_build_query(request()->query());
        $pageUrl = $query ? $pageUrl . '?' . $query : $pageUrl;

        return redirect()->to($pageUrl);
    }

    /**
     * Return the URL of the current page.
     *
     * Handle static and cms pages.
     *
     * @return string
     */
    protected function getUrl()
    {
        $page = $this->getPage();

        if (isset($page->apiBag['staticPage'])) {
            $staticPage = $page->apiBag['staticPage'];
            $localeUrl  = array_get($staticPage->attributes, 'viewBag.url');
        } else {
            $router    = new RainRouter();
            $params    = $this->getRouter()->getParameters();
            $localeUrl = $router->urlFromPattern($page->url, $params);
        }

        return $localeUrl;
    }

    private function handleGetCurrencySwitch(): ?RedirectResponse
    {
        $currencyId = request()->query(self::QUERY_KEY);
        if ($currencyId === null || $currencyId === '') {
            return null;
        }

        $currency = $this->findEnabledCurrency($currencyId);
        $activeCurrency = Currency::activeCurrency();

        if ($currency && (!$activeCurrency || (int)$currency->id !== (int)$activeCurrency->id)) {
            Currency::setActiveCurrency($currency);
        }

        $query = Arr::except(request()->query(), [self::QUERY_KEY]);
        $url = request()->url();
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return redirect()->to($url);
    }

    private function findEnabledCurrency($value): ?Currency
    {
        $query = Currency::where('is_enabled', true);

        if (is_numeric($value)) {
            return $query->where('id', (int)$value)->first();
        }

        $code = strtoupper(trim((string)$value));
        if ($code === '') {
            return null;
        }

        return $query->where('code', $code)->first();
    }
}
