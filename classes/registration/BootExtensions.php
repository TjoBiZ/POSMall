<?php

namespace KodZero\POSMall\Classes\Registration;

use Backend;
use Backend\Widgets\Filter;
use Backend\Widgets\Form;
use Backend\Widgets\Lists;
use Event;
use October\Rain\Database\Builder;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\CustomerGroup;
use KodZero\POSMall\Models\Tax;
use RainLab\Location\Models\Country as RainLabCountry;
use RainLab\User\Controllers\Users as RainLabUsersController;
use RainLab\User\Models\User as RainLabUser;
use System\Classes\PluginManager;

trait BootExtensions
{
    protected function registerExtensions()
    {
        if (PluginManager::instance()->exists('RainLab.Location')) {
            $this->extendRainLabCountry();
        }

        if (PluginManager::instance()->exists('RainLab.User')) {
            $this->extendRainLabUser();
        }
    }

    protected function extendRainLabCountry()
    {
        RainLabCountry::extend(function ($model) {
            $model->belongsToMany['taxes'] = [
                Tax::class,
                'table'    => 'kodzero_posmall_country_tax',
                'key'      => 'country_id',
                'otherKey' => 'tax_id',
            ];
        });
    }

    protected function extendRainLabUser()
    {
        RainLabUser::extend(function (RainLabUser $model) {
            $model->hasOne['posmall_customer'] = Customer::class;
            $model->belongsTo['posmall_customer_group'] = [CustomerGroup::class, 'key' => 'kodzero_posmall_customer_group_id'];
            $model->hasManyThrough['posmall_addresses'] = [
                Address::class,
                'key'        => 'user_id',
                'through'    => Customer::class,
                'throughKey' => 'id',
            ];
            $model->addFillable([
                'posmall_customer_group',
                'kodzero_posmall_customer_group_id',
            ]);

            // RainLab.User 3.0
            if (class_exists(\RainLab\User\Models\Setting::class)) {
                $model->rules['first_name'] = 'required';
                $model->rules['last_name']  = 'required';
            } else {
                $model->rules['surname'] = 'required';
                $model->rules['name']    = 'required';
            }

            $model->addDynamicMethod('scopePosmallCustomer', function (Builder $builder) {
                $builder->whereIn('id', Customer::query()->select('user_id'));

                return $builder;
            });

            $model->addDynamicMethod('scopeHasPosmallCustomerFilter', function (Builder $builder, $scopes) {
                if ($scopes->value == '1') {
                    $builder->whereNotIn('id', Customer::query()->select('user_id'));
                } elseif ($scopes->value == '2') {
                    $builder->whereIn('id', Customer::query()->select('user_id'));
                }

                return $builder;
            });

            // Create a customer for a User model that does not have a customer attached.
            $model->addDynamicMethod('attachCustomer', function () use ($model) {
                if (Customer::forUser($model) || $model->is_guest) {
                    return;
                }

                $customer = Customer::ensureForUser($model);

                if (method_exists($model, 'setRelation')) {
                    $model->setRelation('posmall_customer', $customer);
                }
            });
        });

        if (!app()->runningInBackend()) {
            return;
        }

        RainLabUsersController::extend(function (RainLabUsersController $users) {
            if (!isset($users->relationConfig)) {
                $users->addDynamicProperty('relationConfig');
            }

            $myConfigPath = '$/kodzero/posmall/controllers/users/config_relation.yaml';
            $users->relationConfig = $users->mergeConfig(
                $users->relationConfig,
                $myConfigPath
            );

            // Extend the Users controller with the Relation behaviour that is needed
            // to display the POSMall addresses relation widget above.
            // RainLab.User 3.0 does not need this.
            if (!class_exists(\RainLab\User\Models\Setting::class)) {
                if (!$users->isClassExtendedWith(Backend\Behaviors\RelationController::class)) {
                    $users->extendClassWith(Backend\Behaviors\RelationController::class);
                }
            }
        });

        // Add Customer Groups menu entry to RainLab.User
        Event::listen('backend.menu.extendItems', function ($manager) {
            $manager->addSideMenuItems('RainLab.User', 'user', [
                'customer_groups' => [
                    'label'       => 'kodzero.posmall::lang.common.customer_groups',
                    'url'         => Backend::url('kodzero/posmall/customergroups'),
                    'icon'        => 'icon-users',
                    'permissions' => ['kodzero.posmall.manage_customer_groups'],
                ],
            ]);
            $manager->addSideMenuItems('RainLab.User', 'user', [
                'customer_addresses' => [
                    'label'       => 'kodzero.posmall::lang.common.addresses',
                    'url'         => Backend::url('kodzero/posmall/addresses'),
                    'icon'        => 'icon-home',
                    'permissions' => ['kodzero.posmall.manage_customer_addresses'],
                ],
            ]);
        }, 5);

        // Add Customer Groups relation to RainLab.User form
        Event::listen('backend.form.extendFields', function (Form $widget) {
            if (! $widget->getController() instanceof RainLabUsersController) {
                return;
            }

            if (! $widget->model instanceof RainLabUser) {
                return;
            }

            $widget->addTabFields([
                'posmall_customer_group' => [
                    'label'       => trans('kodzero.posmall::lang.common.customer_group'),
                    'type'        => 'relation',
                    'nameFrom'    => 'name',
                    'emptyOption' => trans('kodzero.posmall::lang.common.none'),
                    'tab'         => 'kodzero.posmall::lang.plugin.name',
                ],
                //'posmall_addresses'      => [
                //    'label' => trans('kodzero.posmall::lang.common.addresses'),
                //    'type'  => 'partial',
                //    'path'  => '$/kodzero/posmall/controllers/users/_addresses.htm',
                //    'tab'   => 'kodzero.posmall::lang.plugin.name',
                //],
            ]);
        }, 5);

        // Add Customer Group on RainLab.User List
        Event::listen('backend.list.extendColumns', function (Lists $list) {
            if (!$list->getController() instanceof RainLabUsersController) {
                return;
            }

            if (!$list->getModel() instanceof RainLabUser) {
                return;
            }

            // Add a new column
            $list->addColumns([
                'posmall_customer_group' => [
                    'label'     => trans('kodzero.posmall::lang.common.customer_group'),
                    'default'   => '',
                    'after'     => 'email',
                    'relation'  => 'posmall_customer_group',
                    'select'    => 'name',
                    'sortable'  => true,
                ],
            ]);
        });

        // Add Customer Group on RainLab.User List
        Event::listen('backend.filter.extendScopes', function (Filter $filter) {
            if (!$filter->getController() instanceof RainLabUsersController) {
                return;
            }

            if (!$filter->getModel() instanceof RainLabUser) {
                return;
            }

            $filter->addScopes([
                'has_posmall_customer' => [
                    'label'         => trans('kodzero.posmall::lang.order.customer'),
                    'type'          => 'switch',
                    'conditions'    => [
                        'kodzero_posmall_customers.id = null',
                        'kodzero_posmall_customers.id <> null',
                    ],
                    'modelScope'    => 'hasPosmallCustomerFilter',
                ],
            ]);
        });
    }
}
