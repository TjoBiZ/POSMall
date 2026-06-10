<?php

namespace KodZero\POSMall\Classes\Registration;

use KodZero\POSMall\Classes\Utils\Money;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Classes\PageSpeed\StorefrontAssetOptimizer;
use System\Twig\Extension as TwigExtension;
use System\Twig\Loader as TwigLoader;
use Twig\Environment;

trait BootTwig
{
    public function registerTwigEnvironment()
    {
        $this->app->singleton('posmall.twig.environment', function () {
            $twig = new Environment(new TwigLoader(), ['auto_reload' => true]);
            $twig->addExtension(new TwigExtension());

            return $twig;
        });
    }

    public function registerMarkupTags()
    {
        $filters = [
            'money' => fn (...$args) => app(Money::class)->format(...$args),
        ];

        // Check the translate plugin is installed
        if (! class_exists('RainLab\Translate\Behaviors\TranslatableModel')) {
            $filters['_']  = ['Lang', 'get'];
            $filters['__'] = ['Lang', 'choice'];
        }

        return [
            'filters' => $filters,
            'functions' => [
                'posmall_catalog_image_sources' => fn ($item) => app(CatalogImageOptimizer::class)->catalogSources($item),
                'posmall_image_sources' => fn ($image, string $alt = 'POSMall image', string $profile = CatalogImageOptimizer::PROFILE_CATALOG) => app(CatalogImageOptimizer::class)->imageSources($image, $alt, $profile),
                'posmall_pagespeed_asset_path' => fn (string $type) => app(StorefrontAssetOptimizer::class)->assetPath($type),
                'posmall_pagespeed_asset_version' => fn (string $type) => app(StorefrontAssetOptimizer::class)->assetVersion($type),
            ],
        ];
    }
}
