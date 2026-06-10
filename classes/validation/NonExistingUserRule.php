<?php

namespace KodZero\POSMall\Classes\Validation;

use RainLab\User\Models\User;
use KodZero\POSMall\Models\Customer;

class NonExistingUserRule
{
    public function validate($attribute, $value, $parameters, $validator)
    {
        return User::where('email', $value)
            ->whereIn('id', Customer::where('is_guest', 0)->pluck('user_id'))
            ->count() === 0;
    }
}
