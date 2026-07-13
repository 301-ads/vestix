<?php

namespace App\Services;

use App\Models\BankrollSnapshot;
use App\Models\User;
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

    public function recordSnapshot(User $user, float $amount, ?Carbon $date = null): BankrollSnapshot
    {
        $recordedOn = ($date ?? now($this->timezone()))->copy()->startOfDay();
        $benchmarkClose = $this->benchmarkCloseResolver->resolveTradingDayClose($recordedOn);

        $snapshot = BankrollSnapshot::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'recorded_on' => $recordedOn->toDateString(),
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
        return $user->bankrollSnapshots()
            ->orderBy('recorded_on')
            ->get();
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
