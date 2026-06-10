<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

use Cms\Classes\ComponentBase;

class EnhancedEcommerceAnalytics extends ComponentBase
{
    public $products;

    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.enhancedEcommerceAnalytics.details.name',
            'description' => 'kodzero.posmall::lang.components.enhancedEcommerceAnalytics.details.description',
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function init()
    {
    }
}
