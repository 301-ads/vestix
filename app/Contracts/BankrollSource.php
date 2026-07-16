<?php

namespace App\Contracts;

use App\Models\User;

interface BankrollSource
{
    public function resolveAmount(User $user): float;
}
