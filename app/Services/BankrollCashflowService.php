<?php

namespace App\Services;

use App\Enums\BankrollCashflowType;
use App\Models\BankrollCashflow;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BankrollCashflowService
{
    public function record(
        User $user,
        BankrollCashflowType $type,
        float $amount,
        ?Carbon $occurredOn = null,
        ?string $note = null,
        string $source = 'manual',
        ?string $externalId = null,
    ): BankrollCashflow {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Cashflow amount must be positive.');
        }

        $occurredOn ??= now($this->timezone())->startOfDay();

        $cashflow = $user->bankrollCashflows()->create([
            'type' => $type,
            'amount' => round(abs($amount), 2),
            'occurred_on' => $occurredOn->toDateString(),
            'note' => filled($note) ? trim($note) : null,
            'source' => $source,
            'external_id' => filled($externalId) ? trim($externalId) : null,
        ]);

        // Day-1 cutover: first cashflow stamps baseline_date so older Revolut snapshots stay out.
        if ($user->baseline_date === null) {
            $user->forceFill([
                'baseline_date' => $occurredOn->toDateString(),
                'baseline_capital' => null,
            ])->save();
        }

        return $cashflow;
    }

    public function update(
        User $user,
        int $cashflowId,
        BankrollCashflowType $type,
        float $amount,
        Carbon $occurredOn,
        ?string $note = null,
    ): ?BankrollCashflow {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Cashflow amount must be positive.');
        }

        $cashflow = $user->bankrollCashflows()->whereKey($cashflowId)->first();

        if ($cashflow === null) {
            return null;
        }

        $cashflow->update([
            'type' => $type,
            'amount' => round(abs($amount), 2),
            'occurred_on' => $occurredOn->toDateString(),
            'note' => filled($note) ? trim($note) : null,
        ]);

        return $cashflow->fresh();
    }

    /**
     * Net external capital in since baseline (deposits − withdrawals), as of a date inclusive.
     */
    public function netExternalIn(User $user, ?Carbon $asOf = null): float
    {
        $asOf ??= now($this->timezone())->startOfDay();

        $query = $user->bankrollCashflows()
            ->whereDate('occurred_on', '<=', $asOf->toDateString());

        if ($user->baseline_date !== null) {
            $query->whereDate('occurred_on', '>=', $user->baseline_date->toDateString());
        }

        $flows = $query->get(['type', 'amount']);

        $net = 0.0;

        foreach ($flows as $flow) {
            $net += $flow->signedAmount();
        }

        return round($net, 2);
    }

    /**
     * @return Collection<int, BankrollCashflow>
     */
    public function recentForUser(User $user, int $limit = 10): Collection
    {
        return $user->bankrollCashflows()
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function deleteForUser(User $user, int $cashflowId): bool
    {
        $cashflow = $user->bankrollCashflows()->whereKey($cashflowId)->first();

        if ($cashflow === null) {
            return false;
        }

        return (bool) $cashflow->delete();
    }

    public function timezone(): string
    {
        return (string) config('vestix.bankroll_tracker.timezone', 'Europe/Amsterdam');
    }
}
