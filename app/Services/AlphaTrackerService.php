<?php

namespace App\Services;

use App\Models\BankrollSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;

class AlphaTrackerService
{
    public function __construct(
        private BankrollSnapshotService $bankrollSnapshots,
    ) {}

    public function hasEnoughSnapshots(User $user): bool
    {
        return $this->bankrollSnapshots->snapshotsForUser($user)->count() >= 2;
    }

    /**
     * @return array{
     *     portfolio_ytd: float|null,
     *     benchmark_ytd: float|null,
     *     alpha_ytd: float|null,
     * }
     */
    public function ytdStats(User $user): array
    {
        $snapshots = $this->bankrollSnapshots->snapshotsForUser($user);

        if ($snapshots->count() < 2) {
            return [
                'portfolio_ytd' => null,
                'benchmark_ytd' => null,
                'alpha_ytd' => null,
            ];
        }

        $year = now($this->bankrollSnapshots->timezone())->year;
        $ytdBaseline = $this->resolveYtdBaseline($user, $snapshots, $year);
        $latest = $snapshots->last();

        if ($ytdBaseline === null || $latest === null) {
            return [
                'portfolio_ytd' => null,
                'benchmark_ytd' => null,
                'alpha_ytd' => null,
            ];
        }

        $portfolioBaselineAmount = $this->portfolioBaselineAmount($user, $ytdBaseline);

        $portfolioYtd = $this->growthPercent(
            $portfolioBaselineAmount,
            (float) $latest->amount,
        );

        $benchmarkYtd = $this->benchmarkGrowthPercent($ytdBaseline, $latest);

        return [
            'portfolio_ytd' => $portfolioYtd,
            'benchmark_ytd' => $benchmarkYtd,
            'alpha_ytd' => $benchmarkYtd !== null && $portfolioYtd !== null
                ? round($portfolioYtd - $benchmarkYtd, 2)
                : null,
        ];
    }

    /**
     * @return array<int, array{
     *     date: string,
     *     amount: float,
     *     portfolio_pct: float,
     *     benchmark_pct: float|null,
     *     alpha_pct: float|null,
     * }>
     */
    public function growthCurve(User $user): array
    {
        $snapshots = $this->bankrollSnapshots->snapshotsForUser($user);

        if ($snapshots->count() < 2) {
            return [];
        }

        $baseline = $snapshots->first();
        $baselineAmount = $this->portfolioBaselineAmount($user, $baseline);
        $baselineBenchmark = $baseline->benchmark_close !== null
            ? (float) $baseline->benchmark_close
            : null;

        return $snapshots
            ->map(function (BankrollSnapshot $snapshot) use ($baselineAmount, $baselineBenchmark): array {
                $portfolioPct = $this->growthPercent($baselineAmount, (float) $snapshot->amount) ?? 0.0;
                $benchmarkPct = null;
                $alphaPct = null;

                if (
                    $baselineBenchmark !== null
                    && $baselineBenchmark > 0
                    && $snapshot->benchmark_close !== null
                ) {
                    $benchmarkPct = $this->growthPercent($baselineBenchmark, (float) $snapshot->benchmark_close);
                    $alphaPct = $benchmarkPct !== null
                        ? round($portfolioPct - $benchmarkPct, 2)
                        : null;
                }

                return [
                    'date' => $snapshot->recorded_on->format('Y-m-d'),
                    'amount' => (float) $snapshot->amount,
                    'portfolio_pct' => $portfolioPct,
                    'benchmark_pct' => $benchmarkPct,
                    'alpha_pct' => $alphaPct,
                ];
            })
            ->values()
            ->all();
    }

    private function portfolioBaselineAmount(User $user, BankrollSnapshot $fallbackSnapshot): float
    {
        if ($user->baseline_capital !== null && (float) $user->baseline_capital > 0) {
            return (float) $user->baseline_capital;
        }

        return (float) $fallbackSnapshot->amount;
    }

    /**
     * @param  Collection<int, BankrollSnapshot>  $snapshots
     */
    private function resolveYtdBaseline(User $user, Collection $snapshots, int $year): ?BankrollSnapshot
    {
        // Vestix 2.0: when a Day-1 baseline is set, Alpha always starts from that cutover.
        if ($user->baseline_date !== null && $user->baseline_capital !== null) {
            return $snapshots->first();
        }

        $ytdSnapshots = $snapshots->filter(
            fn (BankrollSnapshot $snapshot): bool => (int) $snapshot->recorded_on->year === $year,
        );

        if ($ytdSnapshots->isNotEmpty()) {
            return $ytdSnapshots->first();
        }

        return $snapshots->first();
    }

    private function benchmarkGrowthPercent(BankrollSnapshot $baseline, BankrollSnapshot $latest): ?float
    {
        if ($baseline->benchmark_close === null || $latest->benchmark_close === null) {
            return null;
        }

        return $this->growthPercent(
            (float) $baseline->benchmark_close,
            (float) $latest->benchmark_close,
        );
    }

    private function growthPercent(float $from, float $to): ?float
    {
        if ($from <= 0) {
            return null;
        }

        return round((($to / $from) - 1) * 100, 2);
    }
}
