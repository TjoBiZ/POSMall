<?php

namespace KodZero\POSMall\Classes\Registration;

use Barryvdh\DomPDF\Facade;
use Barryvdh\DomPDF\PDF;
use Dompdf\Dompdf;
use Hashids\Hashids;
use Illuminate\Foundation\AliasLoader;
use KodZero\POSMall\Classes\Customer\DefaultSignInHandler;
use KodZero\POSMall\Classes\Customer\DefaultSignUpHandler;
use KodZero\POSMall\Classes\Customer\SignInHandler;
use KodZero\POSMall\Classes\Customer\SignUpHandler;
use KodZero\POSMall\Classes\Index\Index;
use KodZero\POSMall\Classes\Index\PostgreSQL\PostgreSQL;
use KodZero\POSMall\Classes\Payments\DefaultPaymentGateway;
use KodZero\POSMall\Classes\Payments\Offline;
use KodZero\POSMall\Classes\Payments\PaymentGateway;
use KodZero\POSMall\Classes\Payments\PayPalRest;
use KodZero\POSMall\Classes\Payments\PostFinance;
use KodZero\POSMall\Classes\Payments\Stripe;
use KodZero\POSMall\Classes\Totals\TotalsCalculator;
use KodZero\POSMall\Classes\PageSpeed\StorefrontAssetOptimizer;
use KodZero\POSMall\Classes\User\UserProvider;
use KodZero\POSMall\Classes\Utils\DefaultMoney;
use KodZero\POSMall\Classes\Utils\Money;
use KodZero\POSMall\Classes\Payments\StripeHostedCheckout;

trait BootServiceContainer
{
    protected function registerServices()
    {
        $this->app->bind(SignInHandler::class, fn () => new DefaultSignInHandler());
        $this->app->bind(SignUpHandler::class, fn () => new DefaultSignUpHandler());
        $this->app->bind(TotalsCalculator::class, fn ($app, $params) => new TotalsCalculator($params[0] ?? null));
        $this->app->singleton(Money::class, fn () => new DefaultMoney());
        $this->app->singleton(PaymentGateway::class, function () {
            $gateway = new DefaultPaymentGateway();
            $gateway->registerProvider(new Offline());
            $gateway->registerProvider(new PayPalRest());
            $gateway->registerProvider(new Stripe());
            $gateway->registerProvider(new StripeHostedCheckout());
            $gateway->registerProvider(new PostFinance());
            return $gateway;
        });
        $this->app->singleton(Hashids::class, fn () => new Hashids(config('app.key', 'posmall'), 8));
        // PostgreSQL-only branch: legacy index drivers remain in the tree but are not container-bound.
        $this->app->singleton(Index::class, fn () => new PostgreSQL());
        if (method_exists($this->app, 'scoped')) {
            $this->app->scoped(StorefrontAssetOptimizer::class);
        } else {
            $this->app->bind(StorefrontAssetOptimizer::class);
        }
    }

    protected function registerDeferredRequestServices()
    {
        $this->registerDomPDF();
        $this->registerUserProvider();
    }

    /**
     * Register barryvdh/laravel-dompdf
     */
    protected function registerDomPDF()
    {
        AliasLoader::getInstance()->alias('PDF', Facade::class);

        $this->app->bind('dompdf.options', function () {
            if ($defines = $this->app['config']->get('kodzero.posmall::pdf.defines')) {
                $options = [];

                foreach ($defines as $key => $value) {
                    $key           = strtolower(str_replace('DOMPDF_', '', $key));
                    $options[$key] = $value;
                }
            } else {
                $options = $this->app['config']->get('kodzero.posmall::pdf.options', []);
            }

            return $options;
        });

        $this->app->bind('dompdf', function () {
            $options = $this->app->make('dompdf.options');
            $dompdf  = new Dompdf($options);
            $dompdf->setBasePath(realpath(base_path('public')));

            return $dompdf;
        });
        $this->app->alias('dompdf', Dompdf::class);
        $this->app->bind('dompdf.wrapper', fn ($app) => new PDF($app['dompdf'], $app['config'], $app['files'], $app['view']));
    }

    protected function registerUserProvider()
    {
        // RainLab.User 3.0
        if (class_exists(\RainLab\User\Models\Setting::class)) {
            // RainLab.User excludes guests from logging in starting with 3.0.
            // We handle these restrictions ourselves, so we can allow guests to log in.
            $this->app->auth->provider('user', fn ($app, array $config) => new UserProvider($app['hash'], $config['model']));
        }
    }
}
