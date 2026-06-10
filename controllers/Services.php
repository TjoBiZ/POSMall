<?php

declare(strict_types=1);

namespace KodZero\POSMall\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Behaviors\RelationController;
use Backend\Classes\Controller;
use BackendMenu;
use DB;
use October\Rain\Support\Facades\Flash;
use KodZero\POSMall\Classes\Database\IsStatesScope;
use KodZero\POSMall\Classes\Images\CatalogImageOptimizer;
use KodZero\POSMall\Classes\Traits\TaxListSorting;
use KodZero\POSMall\Models\Price;
use KodZero\POSMall\Models\Service;

class Services extends Controller
{
    use TaxListSorting;

    public $turboVisitControl = 'disabled';

    /**
     * Implement behaviors for this controller.
     * @var array
     */
    public $implement = [
        ListController::class,
        FormController::class,
        RelationController::class,
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
     * The configuration file for the relation controller implementation.
     * @var string
     */
    public $relationConfig = 'config_relation.yaml';

    /**
     * Required admin permission to access this page.
     * @var array
     */
    public $requiredPermissions = [
        'kodzero.posmall.manage_services',
    ];

    /**
     * Construct the controller.
     */
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('KodZero.POSMall', 'posmall-catalogue', 'posmall-services');
    }

    /**
     * Handle relation on reorder.
     * @return void
     */
    public function onReorderRelation()
    {
        $records = request()->input('rcd');
        $model   = Service::findOrFail($this->params[0]);
        $model->setRelationOrder('options', $records, range(1, count($records)));

        Flash::success(trans('kodzero.posmall::lang.common.sorting_updated'));
    }

    /**
     * Undocumented function
     * @return mixed
     */
    public function onRelationManageCreate()
    {
        $this->asExtension(RelationController::class)->onRelationManageCreate();

        // Store the pricing information with the custom fields.
        if ($this->relationName === 'options') {
            $this->updatePrices($this->relationModel, '_prices');
            $this->relationModel->load('prices.currency');
        }

        return $this->asExtension(RelationController::class)->relationRefresh();
    }

    /**
     * Undocumented function
     * @return mixed
     */
    public function onRelationManageUpdate()
    {
        $this->asExtension(RelationController::class)->onRelationManageUpdate();

        // Store the pricing information with the custom fields.
        if ($this->relationName === 'options') {
            $model = $this->relationModel->find($this->vars['relationManageId']);
            $this->updatePrices($model, '_prices');
            $model->load('prices.currency');
        }

        return $this->asExtension(RelationController::class)->relationRefresh();
    }

    public function formAfterCreate(Service $model): void
    {
        $this->optimizeServiceImages($model);
    }

    public function formAfterUpdate(Service $model): void
    {
        $this->optimizeServiceImages($model);
    }

    /**
     * Update Prices
     * @param mixed $model
     * @param mixed $field
     * @param string $key
     * @return void
     */
    protected function updatePrices($model, $key = 'prices')
    {
        $data = post('MallPrice', []);
        DB::transaction(function () use ($model, $key, $data) {
            foreach ($data as $currency => $_data) {
                $value = array_get($_data, $key);

                if ($value === '') {
                    $value = null;
                }

                Price::withoutGlobalScope(new IsStatesScope())->updateOrCreate([
                    'price_category_id' => null,
                    'priceable_id'      => $model->id,
                    'priceable_type'    => $model::MORPH_KEY,
                    'currency_id'       => $currency,
                ], [
                    'price' => $value,
                ]);
            }
        });
    }

    private function optimizeServiceImages(Service $model): void
    {
        app(CatalogImageOptimizer::class)->optimizeAttachedModelImages(Service::class, (int)$model->id);
    }
}
