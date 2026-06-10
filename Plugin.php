<?php

declare(strict_types=1);

namespace KodZero\POSMall;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use October\Rain\Database\Relations\Relation;
use KodZero\POSMall\Classes\Registration\BootComponents;
use KodZero\POSMall\Classes\Registration\BootEvents;
use KodZero\POSMall\Classes\Registration\BootExtensions;
use KodZero\POSMall\Classes\Registration\BootMails;
use KodZero\POSMall\Classes\Registration\BootServiceContainer;
use KodZero\POSMall\Classes\Registration\BootSettings;
use KodZero\POSMall\Classes\Registration\BootTwig;
use KodZero\POSMall\Classes\Registration\BootValidation;
use KodZero\POSMall\Classes\Http\PublicStorefrontCache;
use KodZero\POSMall\Classes\System\SchedulerCronStatus;
use KodZero\POSMall\Console\CheckCommand;
use KodZero\POSMall\Console\CreateApiTokenCommand;
use KodZero\POSMall\Console\IndexCommand;
use KodZero\POSMall\Console\LoadBenchmarkCommand;
use KodZero\POSMall\Console\OptimizeCatalogImagesCommand;
use KodZero\POSMall\Console\OptimizeStorefrontAssetsCommand;
use KodZero\POSMall\Console\POSMallTestsRun;
use KodZero\POSMall\Console\PurgeCommand;
use KodZero\POSMall\Console\SeedCommonProperties;
use KodZero\POSMall\Console\SeedDataCommand;
use KodZero\POSMall\Console\SeedWingsOfWinCatalog;
use KodZero\POSMall\Console\UpdateUsaTaxesCommand;
use KodZero\POSMall\Models\CustomField;
use KodZero\POSMall\Models\CustomFieldOption;
use KodZero\POSMall\Models\Discount;
use KodZero\POSMall\Models\ImageSet;
use KodZero\POSMall\Models\GeneralSettings;
use KodZero\POSMall\Models\PaymentMethod;
use KodZero\POSMall\Models\Product;
use KodZero\POSMall\Models\ServiceOption;
use KodZero\POSMall\Models\ShippingMethod;
use KodZero\POSMall\Models\ShippingMethodRate;
use KodZero\POSMall\Models\Variant;
use System;
use System\Classes\PluginBase;
use Throwable;

class Plugin extends PluginBase
{
    use BootEvents;
    use BootExtensions;
    use BootServiceContainer;
    use BootSettings;
    use BootComponents;
    use BootMails;
    use BootValidation;
    use BootTwig;

    /**
     * Required plugin dependencies.
     * @var array
     */
    public $require = [
        'RainLab.User',
        'RainLab.Location',
        'RainLab.Translate',
    ];

    /**
     * Required model morph-map relations, must be registered n the constructor
     * to make them available when the plugin migrations are run.
     * @var array
     */
    protected $relations = [
        Variant::MORPH_KEY            => Variant::class,
        Product::MORPH_KEY            => Product::class,
        ImageSet::MORPH_KEY           => ImageSet::class,
        Discount::MORPH_KEY           => Discount::class,
        CustomField::MORPH_KEY        => CustomField::class,
        PaymentMethod::MORPH_KEY      => PaymentMethod::class,
        ShippingMethod::MORPH_KEY     => ShippingMethod::class,
        CustomFieldOption::MORPH_KEY  => CustomFieldOption::class,
        ShippingMethodRate::MORPH_KEY => ShippingMethodRate::class,
        ServiceOption::MORPH_KEY      => ServiceOption::class,
    ];

    /**
     * Create a new plugin instance.
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
        Relation::morphMap($this->relations);
    }

    /**
     * Register this plugin.
     * @return void
     */
    public function register()
    {
        $this->registerServices();
        $this->registerTwigEnvironment();
    }

    /**
     * Boot this plugin.
     * @return void
     */
    public function boot()
    {
        $this->registerPublicStorefrontCache();

        if ($this->hasPublicStorefrontCachePreflightHit()) {
            View::share('app_url', config('app.url'));

            return;
        }

        $this->registerDeferredRequestServices();
        $this->registerExtensions();
        $this->registerEvents();
        $this->registerValidationRules();

        $this->registerConsoleCommand('kodzero.posmall.index', IndexCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.tests.run', POSMallTestsRun::class);

        if (app()->environment(['local', 'dev', 'development', 'testing'])) {
            $this->registerConsoleCommand('kodzero.posmall.seedWingsOfWinCatalog', SeedWingsOfWinCatalog::class);
            $this->registerConsoleCommand('kodzero.posmall.loadBenchmark', LoadBenchmarkCommand::class);
        }

        if (!app()->runningInConsole()) {
            View::share('app_url', config('app.url'));

            return;
        }

        $this->registerConsoleCommand('kodzero.posmall.check', CheckCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.apiTokenCreate', CreateApiTokenCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.imagesOptimizeCatalog', OptimizeCatalogImagesCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.pagespeedOptimizeAssets', OptimizeStorefrontAssetsCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.purge', PurgeCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.seed', SeedDataCommand::class);
        $this->registerConsoleCommand('kodzero.posmall.seedCommonProperties', SeedCommonProperties::class);
        $this->registerConsoleCommand('kodzero.posmall.usaTaxes.update', UpdateUsaTaxesCommand::class);

        View::share('app_url', config('app.url'));
    }

    private function hasPublicStorefrontCachePreflightHit(): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        try {
            $request = app('request');

            return $request instanceof Request
                && (new PublicStorefrontCache())->storePreflightHit($request);
        } catch (Throwable) {
            return false;
        }
    }

    private function registerPublicStorefrontCache(): void
    {
        $kernel = app(HttpKernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(PublicStorefrontCache::class);

            return;
        }

        $router = app('router');
        if (method_exists($router, 'prependMiddlewareToGroup')) {
            $router->prependMiddlewareToGroup('web', PublicStorefrontCache::class);

            return;
        }

        $router->pushMiddlewareToGroup('web', PublicStorefrontCache::class);
    }

    public function registerSchedule($schedule)
    {
        if (GeneralSettings::get('usa_auto_update_daily')) {
            $schedule->command('posmall:usa-taxes:update')->dailyAt(app(SchedulerCronStatus::class)->dailyAt())->withoutOverlapping();
        }

        if (! app()->environment(['local', 'dev', 'development', 'testing'])) {
            return;
        }

        $schedule->command('posmall:tests:run', [
            '--scheduled' => true,
            '--trigger'   => 'schedule',
        ])->everyMinute()->withoutOverlapping();
    }

    /**
     * Register Backend-Navigation items for this plugin.
     * @return array
     */
    public function registerNavigation()
    {
        $navigation = parent::registerNavigation();

        // Icon name has been changed from 'icon-star-half-full' to 'icon-star-half'
        if (version_compare(System::VERSION, '3.6', '>=')) {
            $navigation['posmall-catalogue']['sideMenu']['posmall-reviews']['icon'] = 'icon-star-half';
        }

        return $navigation;
    }
}
