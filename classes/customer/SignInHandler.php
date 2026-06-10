<?php

namespace KodZero\POSMall\Classes\Customer;

use RainLab\User\Models\User;

interface SignInHandler
{
    public function handle(array $postData): ?User;
}
