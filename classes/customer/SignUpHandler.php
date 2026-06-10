<?php

namespace KodZero\POSMall\Classes\Customer;

use RainLab\User\Models\User;

interface SignUpHandler
{
    public function handle(array $postData, bool $asGuest = false): ?User;
}
