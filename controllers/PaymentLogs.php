<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;

class PaymentLogs extends Controller
{
    /**
     * Implement behaviors for this controller.
     * @var array
     */
    public $implement = [
        ListController::class,
        FormController::class,
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
     * The configuration file for the filter option within the list controller implementation.
     * @var string
     */
    public $filterConfig = 'config_filter.yaml';

    /**
     * Required admin permission to access this page.
     * @var array
     */
    public $requiredPermissions = [
        'kodzero.posmall.manage_payment_log',
    ];

    /**
     * Construct the controller.
     */
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-orders', 'posmall-payment-log');
    }

    /**
     * Inject row class name.
     * @param mixed $row
     * @param mixed $definition
     * @return mixed
     */
    public function listInjectRowClass($row, $definition)
    {
        if ($row->failed) {
            return 'negative';
        }
    }
}
