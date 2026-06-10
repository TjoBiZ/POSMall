<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Models\OrderState;

class OrderStateTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if ($useDemo) {
            return;
        }
        
        OrderState::firstOrCreate(['flag' => OrderState::FLAG_NEW], [
            'name'  => trans('kodzero.posmall::demo.order_states.new'),
            'flag'  => OrderState::FLAG_NEW,
            'color' => '#3498db',
        ]);
        
        OrderState::firstOrCreate(['name' => trans('kodzero.posmall::demo.order_states.in_progress')], [
            'name'  => trans('kodzero.posmall::demo.order_states.in_progress'),
            'color' => '#f1c40f',
        ]);
        
        OrderState::firstOrCreate(['name' => trans('kodzero.posmall::demo.order_states.disputed')], [
            'name'  => trans('kodzero.posmall::demo.order_states.disputed'),
            'color' => '#d30000',
        ]);
        
        OrderState::firstOrCreate(['flag' => OrderState::FLAG_CANCELLED], [
            'name'  => trans('kodzero.posmall::demo.order_states.cancelled'),
            'flag'  => OrderState::FLAG_CANCELLED,
            'color' => '#5e667f',
        ]);
        
        OrderState::firstOrCreate(['flag' => OrderState::FLAG_COMPLETE], [
            'name'  => trans('kodzero.posmall::demo.order_states.complete'),
            'flag'  => OrderState::FLAG_COMPLETE,
            'color' => '#189e51',
        ]);
    }
}
