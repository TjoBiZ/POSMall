<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Models\ApiToken;

class ApiTokens extends Controller
{
    public $implement = [
        FormController::class,
        ListController::class,
    ];

    public $formConfig = 'config_form.yaml';

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = [
        'kodzero.posmall.manage_api',
    ];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-orders', 'posmall-api-tokens');
    }

    public function onRevoke()
    {
        $token = ApiToken::findOrFail((int)post('id'));
        $token->revoked_at = now();
        $token->save();

        Flash::success('POSMall API token revoked.');

        return $this->listRefresh();
    }
}
