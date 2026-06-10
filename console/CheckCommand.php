<?php

declare(strict_types=1);

namespace KodZero\POSMall\Console;

use Illuminate\Console\Command;
use KodZero\POSMall\Models\Currency;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ShippingMethod;

class CheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     * @var string
     */
    protected $signature = 'posmall:check';

    /**
     * The console command name.
     * @var string
     */
    protected $name = 'posmall:check';

    /**
     * The console command description.
     * @var string|null
     */
    protected $description = 'Check if your setup is complete';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        $this->output->newLine();
        $checks = [
            [
                'title' => 'CMS pages are linked',
                'check' => fn () => $this->checkCMSPages(),
            ],
            [
                'title' => 'A base currency is set',
                'check' => function () {
                    if (Currency::where('is_default', 1)->count() < 1) {
                        return 'You can set this via Backend Settings -> Mall: General -> Currencies';
                    } else {
                        return true;
                    }
                },
            ],
            [
                'title' => 'A admin e-mail is set',
                'check' => function () {
                    if (!GeneralSettings::get('admin_email')) {
                        return 'You can set this via Backend Settings -> Mall: General -> Configuration';
                    } else {
                        return true;
                    }
                },
            ],
            [
                'title' => 'All products have a price in the default currency',
                'check' => fn () => $this->checkProducts(),
            ],
            [
                'title' => 'All products have a category',
                'check' => fn () => $this->checkProductCategories(),
            ],
            [
                'title' => 'All shipping methods have a price in the default currency',
                'check' => fn () => $this->checkShippingMethods(),
            ],
            [
                'title' => 'Address lookup integrations are configured',
                'check' => fn () => $this->checkAddressLookupIntegrations(),
            ],
        ];

        $hints = [];
        $rows  = array_map(function ($item) use (&$hints) {
            $result = $item['check']();

            if ($result !== true) {
                $hints[] = ['title' => $item['title'], 'text' => $result];
            }

            return [$item['title'], $result === true ? 'OK' : 'FAIL'];
        }, $checks);

        $this->output->table([
            'Check',
            'Status',
        ], $rows);

        if (count($hints) < 1) {
            $this->output->newLine();

            return $this->output->success('All checks passed!');
        }

        foreach ($hints as $hint) {
            $this->output->title($hint['title']);
            $this->output->writeln($hint['text']);
            $this->output->newLine(2);
        }
    }

    /**
     * Validate all shipping methods are set up correctly.
     */
    private function checkShippingMethods()
    {
        $currency = Currency::defaultCurrency();

        if (! $currency) {
            return true;
        }

        return $this->formatMissingRows(
            ShippingMethod::query()
                ->select(['id', 'name'])
                ->whereDoesntHave('prices', fn ($query) => $query->where('currency_id', $currency->id))
                ->orderBy('id'),
            'The shipping method "%s (%s)" has no price set for your default currency.'
        );
    }

    /**
     * Validate all shipping methods are set up correctly.
     */
    private function checkProducts()
    {
        $currency = Currency::defaultCurrency();

        if (! $currency) {
            return true;
        }

        return $this->formatMissingRows(
            Product::query()
                ->select(['id', 'name'])
                ->where(function ($query) {
                    $query->whereNull('user_defined_id')
                        ->orWhere('user_defined_id', 'not like', 'POSMALL-SERVICE-CARRIER-%');
                })
                ->whereDoesntHave('prices', fn ($query) => $query->where('currency_id', $currency->id))
                ->orderBy('id'),
            'The product "%s (%s)" has no price set for your default currency.'
        );
    }

    /**
     * Validate all products have a category.
     */
    private function checkProductCategories()
    {
        return $this->formatMissingRows(
            Product::query()
                ->select(['id', 'name'])
                ->whereDoesntHave('categories')
                ->orderBy('id'),
            'The product "%s (%s)" has no category set.'
        );
    }

    /**
     * Validate the cms pages are selected in the backend settings.
     */
    private function checkCMSPages()
    {
        $pages  = ['product_page', 'category_page', 'address_page', 'checkout_page', 'account_page'];
        $errors = [];

        foreach ($pages as $page) {
            if (GeneralSettings::get($page) === null) {
                $errors[] = '- ' . trans('kodzero.posmall::lang.general_settings.' . $page);
            }
        }

        if (count($errors) < 1) {
            return true;
        }

        return "The following pages are not linked to a CMS page. Do this via the backend settings:\n\n" . implode(
            "\n",
            $errors
        );
    }

    private function checkAddressLookupIntegrations()
    {
        $errors = [];

        if (
            (bool)GeneralSettings::get('google_places_address_autocomplete_enabled')
            && trim((string)GeneralSettings::get('google_places_browser_api_key', '')) === ''
        ) {
            $errors[] = 'Google Places address autocomplete is enabled, but the browser API key is empty.';
        }

        if ((bool)GeneralSettings::get('usps_addresses_enabled')) {
            if (trim((string)GeneralSettings::get('usps_addresses_environment', '')) === '') {
                $errors[] = 'USPS address lookup is enabled, but the environment is empty.';
            }

            if (trim((string)GeneralSettings::get('usps_addresses_client_id', '')) === '') {
                $errors[] = 'USPS address lookup is enabled, but the client id is empty.';
            }

            if (trim((string)GeneralSettings::get('usps_addresses_client_secret', '')) === '') {
                $errors[] = 'USPS address lookup is enabled, but the client secret is empty.';
            }
        }

        return count($errors) > 0 ? implode("\n", $errors) : true;
    }

    private function formatMissingRows($query, string $message)
    {
        $total = (clone $query)->count();

        if ($total < 1) {
            return true;
        }

        $errors = $query->limit(50)
            ->get()
            ->map(fn ($model) => sprintf($message, $model->name, $model->id))
            ->all();

        if ($total > count($errors)) {
            $errors[] = sprintf('...and %d more rows.', $total - count($errors));
        }

        return implode("\n", $errors);
    }
}
