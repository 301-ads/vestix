<?php

namespace App\Services;

use App\Contracts\DailyBarProvider;
use App\Enums\BankrollCashflowType;
use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rebuild Alpha Tracker days as IBKR Flex NLV + Revolut lot MTM (HALO/BAC era).
 */
class AlphaIbkrRevolutSnapshotBackfill
{
    public function __construct(
        private DailyBarProvider $dailyBars,
        private BankrollSnapshotService $bankrollSnapshots,
        private BenchmarkCloseResolver $benchmarkCloseResolver,
    ) {}

    /**
     * @param  array<string, float>  $ibkrEquityByDate  Y-m-d => IBKR NLV
     * @param  list<string>|null  $tickers  Limit Revolut lots (e.g. BAC,HALO).
     * @return array{
     *     written: int,
     *     days: list<array{date: string, ibkr: float, revolut: float, amount: float}>
     * }
     */
    public function backfill(
        User $user,
        array $ibkrEquityByDate,
        ?Carbon $from = null,
        ?Carbon $to = null,
        bool $dryRun = false,
        ?array $tickers = null,
    ): array {
        $tz = $this->bankrollSnapshots->timezone();
        $from = ($from ?? $user->baseline_date ?? now($tz))->copy()->timezone($tz)->startOfDay();
        $to = ($to ?? now($tz))->copy()->timezone($tz)->startOfDay();

        $lots = $this->resolveRevolutLots($user, $from, $to, $tickers);
        $closesByTicker = $this->loadCloses(
            $lots->pluck('ticker')->unique()->values()->all(),
            $from,
            $to,
        );
        $spyCloses = $this->benchmarkCloseResolver->closesBetween($from, $to);

        $dates = $this->tradingDates($ibkrEquityByDate, $closesByTicker, $from, $to);
        $written = 0;
        $days = [];
        $lastIbkr = 0.0;
        $lastAmount = 0.0;

        foreach ($dates as $date) {
            $ibkr = $ibkrEquityByDate[$date] ?? null;

            if ($ibkr !== null && $ibkr > 0) {
                $lastIbkr = (float) $ibkr;
            } elseif ($lastIbkr <= 0) {
                $lastIbkr = $this->ibkrDepositsThrough($user, $date);
            }

            $revolut = $this->revolutValueOnDate($lots, $closesByTicker, $date);
            $amount = round($lastIbkr + $revolut, 2);

            $days[] = [
                'date' => $date,
                'ibkr' => round($lastIbkr, 2),
                'revolut' => round($revolut, 2),
                'amount' => $amount,
            ];

            if ($dryRun || $amount <= 0) {
                continue;
            }

            $recordedOn = Carbon::parse($date, $tz)->startOfDay();
            $spy = $spyCloses[$date] ?? $this->priorClose($spyCloses, $date);

            \App\Models\BankrollSnapshot::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'recorded_on' => $recordedOn,
                ],
                [
                    'amount' => $amount,
                    'benchmark_ticker' => $this->benchmarkCloseResolver->benchmarkTicker(),
                    'benchmark_close' => $spy,
                    'recorded_at' => now(),
                ],
            );
            $lastAmount = $amount;
            $written++;
        }

        if (! $dryRun) {
            $user = $user->fresh() ?? $user;
            $latest = $this->bankrollSnapshots->resolveAlphaEquity($user);
            // Pin "today" to live IBKR NLV + open Revolut MTM (BAC).
            $this->bankrollSnapshots->recordSnapshot(
                $user,
                $latest > 0 ? $latest : $lastAmount,
                now($tz)->startOfDay(),
            );
            $user->forceFill([
                'trading_bankroll' => $latest > 0 ? $latest : $lastAmount,
                'revolut_cash' => 0,
            ])->save();
        }

        return ['written' => $written, 'days' => $days];
    }

    /**
     * @return Collection<int, array{
     *     ticker: string,
     *     quantity: float,
     *     held_from: string,
     *     held_until: string|null,
     *     cash_after_exit: float|null,
     *     cash_until: string|null
     * }>
     */
    /**
     * @param  list<string>|null  $tickers
     */
    public function resolveRevolutLots(User $user, Carbon $from, Carbon $to, ?array $tickers = null): Collection
    {
        $fromDate = $from->toDateString();
        $allowed = $tickers === null
            ? null
            : array_map(fn (string $ticker): string => strtoupper(trim($ticker)), $tickers);

        return $user->positions()
            ->get()
            ->filter(fn (Position $position): bool => $position->effectiveBroker() === Broker::Revolut)
            ->filter(function (Position $position) use ($fromDate, $allowed): bool {
                $qty = abs((float) ($position->quantity ?? 0));

                if ($qty <= 0) {
                    return false;
                }

                $ticker = strtoupper((string) $position->ticker);

                if ($allowed !== null && ! in_array($ticker, $allowed, true)) {
                    return false;
                }

                if ($position->closed_at === null) {
                    return true;
                }

                // Ignore Revolut exits before the Alpha baseline window.
                return $position->closed_at->toDateString() >= $fromDate;
            })
            ->map(function (Position $position) use ($fromDate, $user): array {
                $qty = abs((float) $position->quantity);
                $ticker = strtoupper((string) $position->ticker);
                $closedOn = $position->closed_at?->toDateString();
                $exit = $position->exit_price !== null ? (float) $position->exit_price : null;
                $proceeds = $closedOn !== null && $exit !== null
                    ? round($qty * $exit, 2)
                    : null;

                $cashUntil = null;
                if ($proceeds !== null && $proceeds > 0) {
                    $withdrawal = $user->bankrollCashflows()
                        ->where('type', BankrollCashflowType::Withdrawal)
                        ->whereDate('occurred_on', '>=', $closedOn)
                        ->orderBy('occurred_on')
                        ->get()
                        ->first(fn ($flow): bool => abs((float) $flow->amount - $proceeds) < 0.05);

                    // Cash held from sale day through day before matching withdrawal.
                    $cashUntil = $withdrawal !== null
                        ? $withdrawal->occurred_on->copy()->subDay()->toDateString()
                        : $closedOn;
                }

                return [
                    'ticker' => $ticker,
                    'quantity' => $qty,
                    'held_from' => $fromDate,
                    'held_until' => $closedOn !== null
                        ? Carbon::parse($closedOn)->subDay()->toDateString()
                        : null,
                    'cash_after_exit' => $proceeds,
                    'cash_until' => $cashUntil,
                    'sold_on' => $closedOn,
                    'entry_price' => $position->entry_price !== null ? (float) $position->entry_price : null,
                ];
            })
            ->values();
    }

    /**
     * @param  list<string>  $tickers
     * @return array<string, array<string, float>> ticker => [Y-m-d => close]
     */
    private function loadCloses(array $tickers, Carbon $from, Carbon $to): array
    {
        $lookback = max(40, (int) $from->diffInDays($to) + 15);
        $limit = max(60, $lookback + 5);
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $out = [];

        foreach ($tickers as $ticker) {
            $payload = $this->dailyBars->fetchRecentBars($ticker, $lookback, $limit);
            $closes = [];

            foreach ($payload['bars'] ?? [] as $bar) {
                $date = $bar['date'];

                if ($date < $fromDate || $date > $toDate) {
                    continue;
                }

                $closes[$date] = (float) $bar['close'];
            }

            $out[$ticker] = $closes;
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $ibkrEquityByDate
     * @param  array<string, array<string, float>>  $closesByTicker
     * @return list<string>
     */
    private function tradingDates(array $ibkrEquityByDate, array $closesByTicker, Carbon $from, Carbon $to): array
    {
        $dates = [];

        foreach (array_keys($ibkrEquityByDate) as $date) {
            if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                $dates[$date] = true;
            }
        }

        foreach ($closesByTicker as $closes) {
            foreach (array_keys($closes) as $date) {
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $dates[$date] = true;
                }
            }
        }

        // Always include baseline + today even without bars.
        $dates[$from->toDateString()] = true;
        $dates[$to->toDateString()] = true;

        $list = array_keys($dates);
        sort($list);

        return $list;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lots
     * @param  array<string, array<string, float>>  $closesByTicker
     */
    private function revolutValueOnDate(Collection $lots, array $closesByTicker, string $date): float
    {
        $total = 0.0;

        foreach ($lots as $lot) {
            $heldUntil = $lot['held_until'];
            $soldOn = $lot['sold_on'] ?? null;
            $inShares = $date >= $lot['held_from']
                && ($heldUntil === null || $date <= $heldUntil);

            if ($inShares) {
                $close = $closesByTicker[$lot['ticker']][$date]
                    ?? $this->priorClose($closesByTicker[$lot['ticker']] ?? [], $date)
                    ?? $lot['entry_price'];

                if ($close !== null) {
                    $total += (float) $lot['quantity'] * (float) $close;
                }

                continue;
            }

            $cash = $lot['cash_after_exit'];
            $cashUntil = $lot['cash_until'];

            if (
                $cash !== null
                && $soldOn !== null
                && $date >= $soldOn
                && $cashUntil !== null
                && $date <= $cashUntil
            ) {
                $total += (float) $cash;
            }
        }

        return $total;
    }

    /**
     * @param  array<string, float>  $closes
     */
    private function priorClose(array $closes, string $date): ?float
    {
        $prior = null;

        foreach ($closes as $closeDate => $close) {
            if ($closeDate <= $date) {
                $prior = $close;
            }
        }

        return $prior;
    }

    private function ibkrDepositsThrough(User $user, string $date): float
    {
        return round((float) $user->bankrollCashflows()
            ->where('type', BankrollCashflowType::Deposit)
            ->whereDate('occurred_on', '<=', $date)
            ->where(function ($query): void {
                $query->where('note', 'like', 'IBKR%')
                    ->orWhere('note', 'like', '%IBKR deposit%');
            })
            ->sum('amount'), 2);
    }
}
