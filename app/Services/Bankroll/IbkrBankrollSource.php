<?php

namespace App\Services\Bankroll;

use App\Contracts\BankrollSource;
use App\Contracts\IbkrAccountReader;
use App\Models\User;

class IbkrBankrollSource implements BankrollSource
{
    public function __construct(
        private IbkrAccountReader $reader,
    ) {}

    public function resolveAmount(User $user): float
    {
        return $this->reader->netLiquidationValue($user);
    }
}
