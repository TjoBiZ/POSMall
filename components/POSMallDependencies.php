<?php

declare(strict_types=1);

namespace KodZero\POSMall\Components;

/**
 * The POSMallDependencies component bundles all needed
 * frontend assets.
 */
class POSMallDependencies extends POSMallComponent
{
    /**
     * Component details.
     *
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'kodzero.posmall::lang.components.dependencies.details.name',
            'description' => 'kodzero.posmall::lang.components.dependencies.details.description',
        ];
    }

    /**
     * Properties of this component.
     *
     * @return array
     */
    public function defineProperties()
    {
        return [
        ];
    }

    /**
     * Inject frontend assets.
     *
     * @return array
     */
    public function init()
    {
        $this->addJs('assets/pubsub.js');
    }
}
