<?php

namespace App\Services\Bankroll;

use App\Contracts\BankrollSource;
use App\Models\User;

class ManualBankrollSource implements BankrollSource
{
    public function __construct(
        private readonly float $amount,
    ) {}

    public function resolveAmount(User $user): float
    {
        return $this->amount;
    }
}
