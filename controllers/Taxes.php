<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use Backend\Widgets\Filter;
use BackendMenu;
use October\Rain\Database\Builder;
use KodZero\POSMall\Classes\Taxes\UsaTaxImporter;
use KodZero\POSMall\Classes\Taxes\UsaStateZipCoverage;
use KodZero\POSMall\Classes\Taxes\UsaTaxSourceRegistry;
use KodZero\POSMall\Classes\Taxes\UsaTaxStagingDisplayGrouper;
use KodZero\POSMall\Classes\Taxes\TaxListSortMapper;
use KodZero\POSMall\Classes\Taxes\UspsAddressZipProvider;
use KodZero\POSMall\Classes\System\SchedulerCronStatus;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\Tax;
use KodZero\POSMall\Models\UsaTaxImportStaging;
use Flash;
use System\Classes\SettingsManager;

class Taxes extends Controller
{
    protected const USA_TAX_SOURCE_PAGE_SIZE = 20;
    protected const USA_TAX_STAGING_PAGE_SIZE = 30;

    /**
     * Implement behaviors for this model.
     * @var array
     */
    public $implement = [
        FormController::class,
        ListController::class,
    ];

    /**
     * The configuration file for the form controller implementation.
     * @var string
     */
    public $formConfig = 'config_form.yaml';

    /**
     * The configuration file for the list controller implementation.
     * @var string
     */
    public $listConfig = 'config_list.yaml';

    /**
     * Required admin permission to access this page.
     * @var array
     */
    public $requiredPermissions = [
        'kodzero.posmall.manage_taxes',
    ];

    /**
     * Construct the controller.
     */
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('KodZero.POSMall', 'tax_settings');
        $this->addJs('/plugins/kodzero/posmall/assets/backend.js');
    }

    public function index()
    {
        $this->asExtension(ListController::class)->index();
        $this->prepareUsaTaxVars();
    }
    
    /**
     * Extend query to show disabled records.
     * @param Builder $query
     * @return void
     */
    public function formExtendQuery(Builder $query)
    {
        $query->withDisabled();
    }
    
    /**
     * Extend query to show disabled records.
     * @param Builder $query
     * @return void
     */
    public function listExtendQuery(Builder $query)
    {
        $query->withDisabled();
    }

    public function listFilterExtendScopes(Filter $filter): void
    {
        if (! $filter->getModel() instanceof Tax) {
            return;
        }

        $this->removeFilterScopeIfPresent($filter, 'state_codes');
        $this->removeFilterScopeIfPresent($filter, 'tax_main_group');

        $scopes = [];
        $order = 100;

        foreach (Tax::availableTaxMainGroupOptions() as $group => $label) {
            $scopes['posmall_tax_group_' . $group] = [
                'label' => $label,
                'shortLabel' => $label,
                'type' => 'checkbox',
                'order' => $order++,
                'value' => 1,
                'modelScope' => [Tax::class, 'applyBackendListFilterScope'],
                'conditions' => $this->taxMainGroupFilterCondition((string)$group),
            ];
        }

        $order = 300;
        foreach (Tax::availableStateCodeOptions() as $state => $label) {
            $scopes['posmall_tax_state_' . strtolower($state)] = [
                'label' => 'State ' . $state,
                'shortLabel' => $state,
                'type' => 'checkbox',
                'order' => $order++,
                'value' => 1,
                'modelScope' => [Tax::class, 'applyBackendListFilterScope'],
                'conditions' => $this->stateFilterCondition((string)$state),
            ];
        }

        $filter->addScopes($scopes);
    }

    protected function stateFilterCondition(string $state): string
    {
        $state = strtoupper(trim($state));

        return sprintf(
            "(state_code = '%s' OR (',' || REPLACE(COALESCE(state_codes, ''), ' ', '') || ',') LIKE '%%,%s,%%')",
            $state,
            $state
        );
    }

    protected function taxMainGroupFilterCondition(string $group): string
    {
        $group = strtolower(trim($group));
        $codes = Tax::taxGroupCodesByMainGroup()[$group] ?? [];
        $quotedCodes = implode(', ', array_map(fn (string $code): string => "'" . str_replace("'", "''", $code) . "'", $codes));
        $conditions = ["tax_main_group = '" . str_replace("'", "''", $group) . "'"];

        if ($quotedCodes !== '') {
            $conditions[] = 'tax_group_code IN (' . $quotedCodes . ')';
            $conditions[] = 'EXISTS ('
                . 'SELECT 1 FROM kodzero_posmall_tax_group_codes '
                . 'WHERE kodzero_posmall_tax_group_codes.tax_id = kodzero_posmall_taxes.id '
                . 'AND kodzero_posmall_tax_group_codes.tax_group_code IN (' . $quotedCodes . ')'
                . ')';
        }

        if ($group === Tax::TAX_MAIN_GROUP_GENERAL) {
            $conditions[] = 'tax_group_code IS NULL';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    protected function removeFilterScopeIfPresent(Filter $filter, string $scopeName): void
    {
        if (array_key_exists($scopeName, $filter->getScopes())) {
            $filter->removeScope($scopeName);
        }
    }

    public function listExtendSortColumn(Builder $query, string $sortColumn, string $sortDirection, $definition = null): void
    {
        app(TaxListSortMapper::class)->apply($query, $sortColumn, $sortDirection);
    }

    public function onLoadSelectedUsaTaxes()
    {
        $this->allowLongUsaTaxImport();
        $states = (array)post('states', []);

        if (!$states) {
            Flash::warning('Select at least one state.');
            $this->prepareUsaTaxVars();

            return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
        }

        $batchId = app(UsaTaxImporter::class)->stageStates($states);

        Flash::success(sprintf('USA tax records were staged in batch %s.', $batchId));
        $this->prepareUsaTaxVars([
            'stagingStateFilters' => $states,
            'stagingPage' => 1,
        ]);

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onLoadStarterUsaTaxes()
    {
        $this->allowLongUsaTaxImport();
        $batchId = app(UsaTaxImporter::class)->stageStarterRecords();

        Flash::success(sprintf('Supported starter USA tax records were staged in batch %s.', $batchId));
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onToggleUsaTaxHelper()
    {
        GeneralSettings::set('usa_tax_helper_enabled', (bool)post('enabled'));
        Flash::success((bool)post('enabled') ? 'USA tax setup helper enabled.' : 'USA tax setup helper disabled.');
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onToggleUsaTaxAutoUpdate()
    {
        $tax = Tax::withDisabled()->find((int)post('id'));

        if (!$tax) {
            Flash::error('Tax row was not found.');

            return $this->asExtension(ListController::class)->listRefresh();
        }

        $enabled = filter_var(post('enabled'), FILTER_VALIDATE_BOOLEAN);

        if ($enabled && !$tax->isSourceBacked()) {
            $tax->usa_auto_update_enabled = false;
            $tax->save();
            Flash::warning('This tax has no source metadata, so automatic source updates cannot be enabled for it.');

            return $this->asExtension(ListController::class)->listRefresh();
        }

        $tax->usa_auto_update_enabled = $enabled;
        $tax->save();

        Flash::success($enabled ? 'Automatic source update enabled for this tax.' : 'Automatic source update disabled for this tax.');
        $this->warnWhenSchedulerCronIsNotDetected($enabled);

        return $this->asExtension(ListController::class)->listRefresh();
    }

    public function onEnableAllUsaTaxAutoUpdate()
    {
        $count = $this->sourceBackedTaxQuery()
            ->where('usa_auto_update_enabled', false)
            ->update(['usa_auto_update_enabled' => true]);

        Flash::success(sprintf(
            'Automatic source update enabled for %d imported/source-backed tax rows. The daily POSMall tax update cron entry must also be configured.',
            $count
        ));
        $this->warnWhenSchedulerCronIsNotDetected(true);

        return $this->asExtension(ListController::class)->listRefresh();
    }

    public function onDisableAllUsaTaxAutoUpdate()
    {
        $count = Tax::withDisabled()
            ->where('usa_auto_update_enabled', true)
            ->update(['usa_auto_update_enabled' => false]);

        Flash::success(sprintf('Automatic source update disabled for %d tax rows.', $count));

        return $this->asExtension(ListController::class)->listRefresh();
    }

    public function onChangeUsaTaxHelperPage()
    {
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onImportSelectedUsaTaxRecords()
    {
        $ids = $this->normalizeStagingRecordIds((array)post('records', []));

        if (!$ids) {
            Flash::warning('Select at least one parsed USA tax record.');
            $this->prepareUsaTaxVars();

            return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
        }

        $count = app(UsaTaxImporter::class)->importStaging($ids);

        Flash::success(sprintf('%d USA tax records were imported to the main Taxes table.', $count));
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onDeleteSelectedUsaTaxRecords()
    {
        $ids = $this->normalizeStagingRecordIds((array)post('records', []));

        if (!$ids) {
            Flash::warning('Select at least one staged USA tax record.');
            $this->prepareUsaTaxVars();

            return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
        }

        $count = app(UsaTaxImporter::class)->deleteStaging($ids);

        Flash::success(sprintf('%d staged USA tax records were removed.', $count));
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onRunUsaTaxUpdateNow()
    {
        $this->allowLongUsaTaxImport();
        $states = \KodZero\POSMall\Models\Tax::where('is_enabled', true)
            ->whereNotNull('state_code')
            ->pluck('state_code')
            ->filter()
            ->unique()
            ->values()
            ->all() ?: ['CA', 'WA', 'TX', 'NY', 'FL', 'OR'];

        $batchId = app(UsaTaxImporter::class)->stageStates($states);

        Flash::success(sprintf('USA tax update staged batch %s. Review parsed records below before importing.', $batchId));
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onBuildUsaZipCoverage()
    {
        $this->allowLongUsaTaxImport();
        $result = app(UsaStateZipCoverage::class)->syncAll();

        Flash::success(
            'ZIP code coverage has been checked and is up to date. This update only checks and loads fallback ZIP code coverage used to help match a customer\'s entered address with the correct U.S. ZIP code. It does not load tax rates and does not enable tax calculation by itself. Tax calculation will only work after the required tax rate data is imported separately and connected to the correct product, service, category, subcategory, or tax group. After the customer\'s ZIP code is identified, the system can use that ZIP code to find the applicable tax rate from the imported tax tables. The result should still be reviewed and verified, because this tool should not be treated as 100% legally guaranteed tax advice.'
        );
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    public function onSaveUspsAddressLookupSettings()
    {
        $provider = app(UspsAddressZipProvider::class);
        $environment = (string)post('usps_addresses_environment', UspsAddressZipProvider::ENVIRONMENT_PRODUCTION);
        $environments = array_keys(UspsAddressZipProvider::environmentOptions());

        if (!in_array($environment, $environments, true)) {
            $environment = UspsAddressZipProvider::ENVIRONMENT_PRODUCTION;
        }

        $settings = [
            'usps_addresses_enabled' => (bool)post('usps_addresses_enabled'),
            'usps_addresses_environment' => $environment,
            'usps_addresses_client_id' => trim((string)post('usps_addresses_client_id')),
            'google_places_address_autocomplete_enabled' => (bool)post('google_places_address_autocomplete_enabled'),
            'google_places_browser_api_key' => trim((string)post('google_places_browser_api_key')),
        ];

        $clientSecret = trim((string)post('usps_addresses_client_secret'));

        if ($clientSecret !== '') {
            $settings['usps_addresses_client_secret'] = $clientSecret;
        }

        GeneralSettings::set($settings);
        $provider->clearTokenCache();

        Flash::success('Address lookup settings were saved. Google Places can suggest street addresses while the customer types. USPS will still be tried for ZIP/ZIP+4 with a 2 second timeout, then POSMall will use the local ZIP fallback if available.');
        $this->prepareUsaTaxVars();

        return ['#usa-tax-helper' => $this->makePartial('usa_tax_helper')];
    }

    protected function prepareUsaTaxVars(array $overrides = []): void
    {
        $this->vars['usaTaxHelperEnabled'] = (bool)GeneralSettings::get('usa_tax_helper_enabled');
        $uspsProvider = app(UspsAddressZipProvider::class);
        $this->vars['uspsAddressesEnabled'] = (bool)GeneralSettings::get('usps_addresses_enabled');
        $this->vars['uspsAddressesEnvironment'] = $uspsProvider->environment();
        $this->vars['uspsAddressEnvironmentOptions'] = UspsAddressZipProvider::environmentOptions();
        $this->vars['uspsAddressesClientId'] = $uspsProvider->configuredClientId();
        $this->vars['uspsAddressesHasClientSecret'] = $uspsProvider->hasConfiguredClientSecret();
        $this->vars['googlePlacesAddressAutocompleteEnabled'] = (bool)GeneralSettings::get('google_places_address_autocomplete_enabled');
        $this->vars['googlePlacesBrowserApiKey'] = (string)GeneralSettings::get('google_places_browser_api_key', '');
        $this->vars['usaTaxCronStatus'] = app(SchedulerCronStatus::class)->check();
        $sourceStates = collect(UsaTaxSourceRegistry::states())->values();
        $sourcePage = $this->pageFromPost('sourcePage', $overrides);
        $sourcePages = $this->pageCount($sourceStates->count(), self::USA_TAX_SOURCE_PAGE_SIZE);
        $sourcePage = min($sourcePage, $sourcePages);

        $this->vars['usaTaxStates'] = $sourceStates
            ->forPage($sourcePage, self::USA_TAX_SOURCE_PAGE_SIZE)
            ->values()
            ->all();
        $this->vars['usaTaxSourcePage'] = $sourcePage;
        $this->vars['usaTaxSourcePages'] = $sourcePages;
        $this->vars['usaTaxSourceTotal'] = $sourceStates->count();
        $this->vars['usaTaxSourcePageSize'] = self::USA_TAX_SOURCE_PAGE_SIZE;

        $stagingStateFilters = $this->normalizeStateFilters($overrides['stagingStateFilters'] ?? post('stagingStateFilters', []));
        $stagingMainGroupFilters = $this->normalizeMainGroupFilters($overrides['stagingMainGroupFilters'] ?? post('stagingMainGroupFilters', []));
        $stagingSort = $this->stagingSort($overrides['stagingSort'] ?? post('stagingSort', 'created'));
        $stagingDirection = $this->sortDirection($overrides['stagingDirection'] ?? post('stagingDirection', 'desc'));

        $stagingQuery = UsaTaxImportStaging::whereIn('status', [
                UsaTaxImportStaging::STATUS_PENDING,
                UsaTaxImportStaging::STATUS_PARSED,
                UsaTaxImportStaging::STATUS_IMPORTED,
            ])
            ->when($stagingStateFilters, fn ($query) => $query->whereIn('state_code', $stagingStateFilters))
            ->when($stagingMainGroupFilters, fn ($query) => $query->taxMainGroup($stagingMainGroupFilters));

        $stagingRows = $stagingQuery->get();
        $grouper = app(UsaTaxStagingDisplayGrouper::class);
        $displayRows = $grouper->group($stagingRows, $grouper->liveTaxKeysFor($stagingRows));
        $displayRows = $this->sortStagingDisplayRows($displayRows, $stagingSort, $stagingDirection);
        $stagingPage = $this->pageFromPost('stagingPage', $overrides);
        $stagingPages = $this->pageCount($displayRows->count(), self::USA_TAX_STAGING_PAGE_SIZE);
        $stagingPage = min($stagingPage, $stagingPages);

        $this->vars['usaTaxStagingRows'] = $displayRows
            ->forPage($stagingPage, self::USA_TAX_STAGING_PAGE_SIZE)
            ->values();
        $this->vars['usaTaxStagingPage'] = $stagingPage;
        $this->vars['usaTaxStagingPages'] = $stagingPages;
        $this->vars['usaTaxStagingTotal'] = $displayRows->count();
        $this->vars['usaTaxStagingPageSize'] = self::USA_TAX_STAGING_PAGE_SIZE;
        $this->vars['usaTaxStagingStates'] = UsaTaxImportStaging::whereIn('status', [
                UsaTaxImportStaging::STATUS_PENDING,
                UsaTaxImportStaging::STATUS_PARSED,
                UsaTaxImportStaging::STATUS_IMPORTED,
            ])
            ->pluck('state_code')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $this->vars['usaTaxMainGroupOptions'] = Tax::taxMainGroupOptions();
        $this->vars['usaTaxStagingMainGroups'] = collect(array_keys($this->vars['usaTaxMainGroupOptions']));
        $this->vars['usaTaxStagingStateFilters'] = $stagingStateFilters;
        $this->vars['usaTaxStagingMainGroupFilters'] = $stagingMainGroupFilters;
        $this->vars['usaTaxStagingSort'] = $stagingSort;
        $this->vars['usaTaxStagingDirection'] = $stagingDirection;
    }

    protected function pageFromPost(string $name, array $overrides = []): int
    {
        return max(1, (int)($overrides[$name] ?? post($name, 1)));
    }

    protected function pageCount(int $total, int $pageSize): int
    {
        return max(1, (int)ceil($total / $pageSize));
    }

    protected function allowLongUsaTaxImport(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
    }

    protected function normalizeStagingRecordIds(array $values): array
    {
        return collect($values)
            ->flatMap(fn ($value) => preg_split('/\s*,\s*/', (string)$value) ?: [])
            ->map(fn ($value) => (int)$value)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizeStateFilters($values): array
    {
        return collect((array)$values)
            ->map(fn ($state) => strtoupper(trim((string)$state)))
            ->filter(fn ($state) => preg_match('/^[A-Z]{2}$/', $state))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function normalizeMainGroupFilters($values): array
    {
        $allowed = array_keys(Tax::taxMainGroupOptions());

        return collect((array)$values)
            ->map(fn ($group) => strtolower(trim((string)$group)))
            ->filter(fn ($group) => in_array($group, $allowed, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    protected function stagingSort($sort): string
    {
        $sort = strtolower(trim((string)$sort));

        return in_array($sort, ['created', 'state', 'main_group', 'rate'], true) ? $sort : 'created';
    }

    protected function sortDirection($direction): string
    {
        return strtolower((string)$direction) === 'asc' ? 'asc' : 'desc';
    }

    protected function sortStagingDisplayRows($rows, string $sort, string $direction)
    {
        $callback = match ($sort) {
            'state' => fn ($row) => [(string)$row->state_code, (string)$row->tax_main_group_display, (float)$row->rate_percent, (int)$row->id],
            'main_group' => fn ($row) => [(string)$row->tax_main_group_display, (string)$row->state_code, (float)$row->rate_percent, (int)$row->id],
            'rate' => fn ($row) => [(float)$row->rate_percent, (string)$row->state_code, (int)$row->id],
            default => fn ($row) => [optional($row->created_at)->timestamp ?? 0, (int)$row->id],
        };

        $sorted = $rows->sortBy($callback, SORT_REGULAR, $direction === 'desc');

        return $sorted->values();
    }

    protected function sourceBackedTaxQuery(): Builder
    {
        return Tax::withDisabled()
            ->where(function (Builder $query) {
                $query->whereNotNull('source_url')
                    ->orWhereNotNull('boundary_source_url')
                    ->orWhereNotNull('source_name')
                    ->orWhereNotNull('parser_name')
                    ->orWhereNotNull('source_hash')
                    ->orWhere(function (Builder $query) {
                        $query->whereNotNull('source_type')
                            ->where('source_type', '!=', '')
                            ->where('source_type', '!=', 'MANUAL')
                            ->where('source_type', '!=', 'manual');
                    });
            });
    }

    protected function warnWhenSchedulerCronIsNotDetected(bool $enabled): void
    {
        if (!$enabled) {
            return;
        }

        $cronStatus = app(SchedulerCronStatus::class)->check();

        if ($cronStatus['state'] !== 'detected') {
            Flash::warning($cronStatus['message'] . ' Required cron entry: ' . $cronStatus['expected_command']);
        }
    }
}
