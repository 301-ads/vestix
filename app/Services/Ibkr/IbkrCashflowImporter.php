<?php

namespace App\Services\Ibkr;

use App\Data\Ibkr\IbkrAccountSnapshot;
use App\Data\Ibkr\IbkrCashflowImportResult;
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
     * Import only external bank transfers. Dividends/fees/interest and FX
     * conversions (EUR.USD sells) are ignored — they are not new capital.
     *
     * EUR bank deposits are converted to account base (USD) via Flex fxRateToBase
     * when present, so Alpha Kapitaalbewegingen stay in USD.
     */
    public function import(User $user, IbkrAccountSnapshot $snapshot): IbkrCashflowImportResult
    {
        $imported = 0;
        $skipped = 0;
        $details = [];
        $baseCurrency = strtoupper($snapshot->baseCurrency);

        foreach ($snapshot->cashTransactions as $transaction) {
            if (! $transaction instanceof IbkrCashTransaction) {
                $skipped++;
                $details[] = $this->detail($transaction, 'invalid_payload');

                continue;
            }

            if ($this->isDenied($transaction)) {
                $skipped++;
                $details[] = $this->detail($transaction, 'denied_type');

                continue;
            }

            if ($this->looksLikeFxConversion($transaction)) {
                $skipped++;
                $details[] = $this->detail($transaction, 'fx_conversion');

                continue;
            }

            $type = $transaction->cashflowType();

            if ($type === null) {
                $skipped++;
                $details[] = $this->detail($transaction, 'not_external_transfer');

                continue;
            }

            $amountBase = $transaction->resolvedAmountInBase($baseCurrency);

            if ($amountBase === null) {
                $skipped++;
                $details[] = $this->detail($transaction, 'missing_fx_rate_to_base');

                continue;
            }

            $exists = $user->bankrollCashflows()
                ->where('source', 'ibkr')
                ->where('external_id', $transaction->externalId)
                ->exists();

            if ($exists) {
                $skipped++;
                $details[] = $this->detail($transaction, 'duplicate', $amountBase);

                continue;
            }

            $this->cashflows->record(
                $user,
                $type,
                $amountBase,
                Carbon::parse($transaction->date, $this->cashflows->timezone())->startOfDay(),
                $this->noteFor($transaction, $type, $baseCurrency, $amountBase),
                'ibkr',
                $transaction->externalId,
            );

            $imported++;
            $details[] = $this->detail($transaction, 'imported', $amountBase);
        }

        return new IbkrCashflowImportResult($imported, $skipped, $details);
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

    /**
     * Spot FX / currency conversion legs (e.g. selling EUR.USD after an EUR deposit).
     * These change currency mix, not external capital — do not book as deposits.
     */
    private function looksLikeFxConversion(IbkrCashTransaction $transaction): bool
    {
        $haystack = strtolower(trim($transaction->type.' '.($transaction->description ?? '')));

        foreach ([
            'currency conversion',
            'forex',
            'fx conversion',
            'spot currency',
            'eur.usd',
            'usd.eur',
        ] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function noteFor(
        IbkrCashTransaction $transaction,
        BankrollCashflowType $type,
        string $baseCurrency,
        float $amountBase,
    ): string {
        $label = $type === BankrollCashflowType::Withdrawal ? 'IBKR withdrawal' : 'IBKR deposit';
        $parts = [$label];

        $currency = strtoupper($transaction->currency);

        if ($currency !== $baseCurrency) {
            $parts[] = sprintf(
                '%s %s → %s %s',
                number_format($transaction->absoluteAmount(), 2, '.', ''),
                $currency,
                number_format($amountBase, 2, '.', ''),
                $baseCurrency,
            );
        }

        if (filled($transaction->description)) {
            $parts[] = $transaction->description;
        }

        return implode(': ', $parts);
    }

    /**
     * @return array{
     *     external_id: string,
     *     type: string,
     *     currency: string,
     *     amount: float,
     *     amount_base: float|null,
     *     reason: string
     * }
     */
    private function detail(mixed $transaction, string $reason, ?float $amountBase = null): array
    {
        if (! $transaction instanceof IbkrCashTransaction) {
            return [
                'external_id' => '—',
                'type' => '—',
                'currency' => '—',
                'amount' => 0.0,
                'amount_base' => null,
                'reason' => $reason,
            ];
        }

        return [
            'external_id' => $transaction->externalId,
            'type' => $transaction->type,
            'currency' => $transaction->currency,
            'amount' => $transaction->amount,
            'amount_base' => $amountBase,
            'reason' => $reason,
        ];
    }
}
