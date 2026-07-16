<?php

namespace App\Services\Bankroll;

use App\Contracts\BankrollSource;
use App\Models\User;
use RuntimeException;

class IbkrBankrollSource implements BankrollSource
{
    public function resolveAmount(User $user): float
    {
        throw new RuntimeException('IBKR bankroll integration is not yet implemented.');
    }
}
