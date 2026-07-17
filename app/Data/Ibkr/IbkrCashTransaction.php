<?php

namespace App\Data\Ibkr;

use App\Enums\BankrollCashflowType;

final readonly class IbkrCashTransaction
{
    public function __construct(
        public string $externalId,
        public string $type,
        public float $amount,
        public string $currency,
        public string $date,
        public ?string $description = null,
    ) {}

    public function isExternalTransfer(): bool
    {
        $normalized = strtolower(trim($this->type));

        foreach (config('vestix.ibkr.cashflow.allowlist', []) as $allowed) {
            if ($normalized === strtolower((string) $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function cashflowType(): ?BankrollCashflowType
    {
        if (! $this->isExternalTransfer()) {
            return null;
        }

        if ($this->amount < 0) {
            return BankrollCashflowType::Withdrawal;
        }

        return BankrollCashflowType::Deposit;
    }

    public function absoluteAmount(): float
    {
        return round(abs($this->amount), 2);
    }
}
