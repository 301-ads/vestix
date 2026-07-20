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
        public ?float $fxRateToBase = null,
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

    /**
     * Amount in account base currency (USD) for Alpha / Kapitaalbewegingen.
     * EUR bank deposits use fxRateToBase from Flex; FX conversions are not external transfers.
     */
    public function resolvedAmountInBase(string $baseCurrency): ?float
    {
        $baseCurrency = strtoupper(trim($baseCurrency));
        $currency = strtoupper(trim($this->currency));

        if ($currency === $baseCurrency) {
            return $this->absoluteAmount();
        }

        foreach (config('vestix.ibkr.cashflow.foreign_deposit_currencies', ['EUR']) as $allowed) {
            if ($currency !== strtoupper((string) $allowed)) {
                continue;
            }

            if ($this->fxRateToBase !== null && $this->fxRateToBase > 0) {
                return round($this->absoluteAmount() * $this->fxRateToBase, 2);
            }

            return null;
        }

        return null;
    }
}
