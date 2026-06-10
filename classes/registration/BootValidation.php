<?php

namespace KodZero\POSMall\Classes\Registration;

use KodZero\POSMall\Classes\Validation\NonExistingUserRule;

trait BootValidation
{
    protected function registerValidationRules()
    {
        $this->registerValidationRule('posmall_non_existing_user', NonExistingUserRule::class);
        $this->registerValidationRule('non_existing_user', NonExistingUserRule::class);
    }
}
