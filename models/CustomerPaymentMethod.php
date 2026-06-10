<?php

declare(strict_types=1);

namespace KodZero\POSMall\Models;

use Model;
use October\Rain\Database\Traits\SoftDelete;
use October\Rain\Database\Traits\Validation;
use KodZero\POSMall\Classes\Traits\HashIds;

class CustomerPaymentMethod extends Model
{
    use Validation;
    use HashIds;
    use SoftDelete;

    public $table = 'kodzero_posmall_customer_payment_methods';

    public $rules = [
        'payment_method_id' => 'required|exists:kodzero_posmall_payment_methods,id',
        'customer_id'       => 'required|exists:kodzero_posmall_customers,id',
    ];

    public $belongsTo = [
        'payment_method' => PaymentMethod::class,
        'customer'       => Customer::class,
    ];

    public $casts = [
        'is_default' => 'boolean',
    ];

    public $fillable = [
        'payment_method_id',
        'customer_id',
        'data',
        'name',
        'is_default',
    ];

    public $jsonable = ['data'];

    public function beforeSave()
    {
        $hasDefault = self::where('customer_id', $this->customer_id)->where('is_default', 1)->count() > 0;

        if (! $hasDefault) {
            $this->is_default = true;
        }
    }
}
