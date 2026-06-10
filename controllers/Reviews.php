<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use Backend\Facades\Backend;
use BackendMenu;
use Illuminate\Support\Facades\Redirect;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Models\Review;

class Reviews extends Controller
{
    /**
     * Implement behaviors for this controller.
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
        'kodzero.posmall.manage_reviews',
    ];

    /**
     * Construct the controller.
     */
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-catalogue', 'posmall-reviews');
    }

    /**
     * Ajax handler on review has been approved.
     * @return mixed
     */
    public function onApprove()
    {
        Review::findOrFail(post('id'))->approve();
        $next = Review::orderBy('created_at')->whereNull('approved_at')->first(['id']);

        if ($next) {
            return Redirect::to(Backend::url('kodzero/posmall/reviews/update/' . $next->id));
        } else {
            Flash::success(trans('kodzero.posmall::lang.reviews.no_more'));

            return Redirect::to(Backend::url('kodzero/posmall/reviews'));
        }
    }
}
