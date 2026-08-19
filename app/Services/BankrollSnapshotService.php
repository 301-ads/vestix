<?php

namespace App\Services;

use App\Contracts\BankrollSource;
use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\Bankroll\ManualBankrollSource;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BankrollSnapshotService
{
    public function __construct(
        private BenchmarkCloseResolver $benchmarkCloseResolver,
    ) {}

    public function timezone(): string
    {
        return (string) config('vestix.bankroll_tracker.timezone', 'Europe/Amsterdam');
    }

    /**
     * Alpha Tracker snapshot date: last completed US RTH session (bankroll timezone).
     * Weekend / premarket saves must not invent a Sunday spike on the chart.
     */
    public function alphaTrackerSessionDate(?Carbon $now = null): Carbon
    {
        $session = UsMarketSession::expectedLastCompletedSessionDate(
            ($now ?? now())->copy()->timezone('America/New_York'),
        );

        return $session
            ->copy()
            ->timezone($this->timezone())
            ->startOfDay();
    }

    /**
     * Alpha Tracker equity: IBKR Net Liquidation (incl. pending deposits logged via exits).
     */
    public function resolveAlphaEquity(User $user, ?float $ibkrNetLiquidation = null): float
    {
        $ibkr = $ibkrNetLiquidation ?? (float) ($user->ibkr_net_liquidation ?? 0);

        return round(max(0.0, $ibkr), 2);
    }

    public function recordSnapshot(User $user, float $amount, ?Carbon $date = null): BankrollSnapshot
    {
        return $this->recordFromSource($user, new ManualBankrollSource($amount), $date);
    }

    public function recordFromSource(User $user, BankrollSource $source, ?Carbon $date = null): BankrollSnapshot
    {
        $amount = $source->resolveAmount($user);

        $recordedOn = ($date ?? now($this->timezone()))->copy()->timezone($this->timezone())->startOfDay();
        $benchmarkClose = $this->benchmarkCloseResolver->resolveTradingDayClose($recordedOn);

        // Use a Carbon date (not toDateString) so SQLite matches stored `YYYY-MM-DD 00:00:00`
        // values; a bare date string misses the row and updateOrCreate tries a duplicate insert.
        $snapshot = BankrollSnapshot::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'recorded_on' => $recordedOn,
            ],
            [
                'amount' => round($amount, 2),
                'benchmark_ticker' => $this->benchmarkCloseResolver->benchmarkTicker(),
                'benchmark_close' => $benchmarkClose,
                'recorded_at' => now(),
            ],
        );

        $user->update(['trading_bankroll' => round($amount, 2)]);

        return $snapshot;
    }

    /**
     * Catch up missing Alpha Tracker days from IBKR Flex EquitySummaryByReportDate.
     *
     * Only fills dates strictly after the newest existing snapshot (missed sync days).
     *
     * @param  array<string, float>  $equityByReportDate  Y-m-d => IBKR NLV
     */
    public function fillMissingFromIbkrDailyEquity(
        User $user,
        array $equityByReportDate,
    ): int {
        $filled = 0;
        $baseline = $user->baseline_date?->copy()->timezone($this->timezone())->startOfDay();
        $latest = $this->latestSnapshot($user);
        $notBefore = $latest?->recorded_on->copy()->timezone($this->timezone())->startOfDay();

        foreach ($equityByReportDate as $date => $ibkrNlv) {
            if ($ibkrNlv <= 0) {
                continue;
            }

            $recordedOn = Carbon::parse((string) $date, $this->timezone())->startOfDay();

            if ($baseline !== null && $recordedOn->lt($baseline)) {
                continue;
            }

            // Require an existing anchor snapshot; only fill forward after it.
            if ($notBefore === null || $recordedOn->lte($notBefore)) {
                continue;
            }

            $exists = BankrollSnapshot::query()
                ->where('user_id', $user->id)
                ->whereDate('recorded_on', $recordedOn->toDateString())
                ->exists();

            if ($exists) {
                continue;
            }

            $this->recordSnapshot(
                $user,
                round((float) $ibkrNlv, 2),
                $recordedOn,
            );
            $filled++;
        }

        return $filled;
    }

    public function latestSnapshot(User $user): ?BankrollSnapshot
    {
        return $user->bankrollSnapshots()
            ->orderByDesc('recorded_on')
            ->first();
    }

    /**
     * @return Collection<int, BankrollSnapshot>
     */
    public function snapshotsForUser(User $user): Collection
    {
        $query = $user->bankrollSnapshots()->orderBy('recorded_on');

        if ($user->baseline_date !== null) {
            $query->whereDate('recorded_on', '>=', $user->baseline_date->toDateString());
        }

        return $query->get();
    }

    /**
     * Prefetch SPY session closes for Alpha densify so the Prestaties chart stays cache-hit only.
     */
    public function warmAlphaBenchmarkCloses(User $user): void
    {
        $snapshots = $this->snapshotsForUser($user);

        if ($snapshots->count() < 2) {
            return;
        }

        $this->benchmarkCloseResolver->warmClosesBetween(
            $snapshots->first()->recorded_on->copy(),
            $snapshots->last()->recorded_on->copy(),
        );
    }

    public function hasSnapshotThisWeek(User $user, ?Carbon $now = null): bool
    {
        $now ??= now($this->timezone());

        return $user->bankrollSnapshots()
            ->where('recorded_on', '>=', $now->copy()->startOfWeek()->toDateString())
            ->exists();
    }

    public function isUpdateDue(User $user, ?Carbon $now = null): bool
    {
        $now ??= now($this->timezone());

        if ($this->hasSnapshotThisWeek($user, $now)) {
            return false;
        }

        $latest = $this->latestSnapshot($user);

        if ($latest !== null && $latest->recorded_on->diffInDays($now) > 7) {
            return true;
        }

        $updateDay = strtolower((string) config('vestix.bankroll_tracker.update_day', 'saturday'));
        $updateDayNumber = match ($updateDay) {
            'sunday' => Carbon::SUNDAY,
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            default => Carbon::SATURDAY,
        };

        return $now->dayOfWeek >= $updateDayNumber;
    }
}
