<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\IbkrAccountSnapshot;
use App\Data\Ibkr\IbkrCashTransaction;
use App\Enums\BankrollCashflowType;
use App\Models\User;
use App\Services\BankrollCashflowService;
use Illuminate\Support\Carbon;

class IbkrCashflowImporter
{
    public function __construct(
        private BankrollCashflowService $cashflows,
    ) {}

    /**
     * Import only external bank transfers. Dividends/fees/interest are ignored.
     *
     * @return array{imported: int, skipped: int}
     */
    public function import(User $user, IbkrAccountSnapshot $snapshot): array
    {
        $imported = 0;
        $skipped = 0;

        foreach ($snapshot->cashTransactions as $transaction) {
            if (! $transaction instanceof IbkrCashTransaction) {
                $skipped++;

                continue;
            }

            if ($this->isDenied($transaction)) {
                $skipped++;

                continue;
            }

            $type = $transaction->cashflowType();

            if ($type === null) {
                $skipped++;

                continue;
            }

            $exists = $user->bankrollCashflows()
                ->where('source', 'ibkr')
                ->where('external_id', $transaction->externalId)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $this->cashflows->record(
                $user,
                $type,
                $transaction->absoluteAmount(),
                Carbon::parse($transaction->date, $this->cashflows->timezone())->startOfDay(),
                $this->noteFor($transaction, $type),
                'ibkr',
                $transaction->externalId,
            );

            $imported++;
        }

        return compact('imported', 'skipped');
    }

    private function isDenied(IbkrCashTransaction $transaction): bool
    {
        $normalized = strtolower(trim($transaction->type));
        $description = strtolower((string) $transaction->description);

        foreach (config('vestix.ibkr.cashflow.denylist', []) as $denied) {
            $needle = strtolower((string) $denied);

            if ($normalized === $needle || str_contains($normalized, $needle) || str_contains($description, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function noteFor(IbkrCashTransaction $transaction, BankrollCashflowType $type): string
    {
        $label = $type === BankrollCashflowType::Withdrawal ? 'IBKR withdrawal' : 'IBKR deposit';

        if (filled($transaction->description)) {
            return $label.': '.$transaction->description;
        }

        return $label;
    }
}
