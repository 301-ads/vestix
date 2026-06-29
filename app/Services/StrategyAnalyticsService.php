<?php

namespace App\Services;

use App\Models\Position;
use App\Models\StrategyTag;
use Illuminate\Support\Collection;

class StrategyAnalyticsService
{
    public const MIN_TRADES_FOR_COACH = 20;

    public function minTradesForCoach(): int
    {
        return max(0, (int) config('vestix.strategy_coach.min_closed_trades', self::MIN_TRADES_FOR_COACH));
    }

    /**
     * @return Collection<int, Position>
     */
    public function closedTradesForUser(int $userId): Collection
    {
        return Position::query()
            ->closed()
            ->forUser($userId)
            ->with('strategyTag')
            ->orderBy('closed_at')
            ->get();
    }

    public function hasEnoughTrades(int $userId): bool
    {
        return $this->closedTradesForUser($userId)->count() >= $this->minTradesForCoach();
    }

    public function tradesUntilCoach(int $userId): int
    {
        $count = $this->closedTradesForUser($userId)->count();

        return max(0, $this->minTradesForCoach() - $count);
    }

    /**
     * @return array<int, array{date: string, cumulative_roi: float}>
     */
    public function equityCurve(int $userId): array
    {
        $curve = [];
        $cumulative = 0.0;

        foreach ($this->closedTradesForUser($userId) as $position) {
            $cumulative += $position->unrealized_pnl_percentage;
            $curve[] = [
                'date' => $position->closed_at?->format('Y-m-d') ?? '',
                'cumulative_roi' => round($cumulative, 2),
            ];
        }

        return $curve;
    }

    /**
     * @return array{
     *     total_trades: int,
     *     win_rate: float,
     *     avg_win: float,
     *     avg_loss: float,
     *     expectancy: float,
     *     max_drawdown: float,
     * }
     */
    public function overallStats(int $userId): array
    {
        $trades = $this->closedTradesForUser($userId);
        $count = $trades->count();

        if ($count === 0) {
            return [
                'total_trades' => 0,
                'win_rate' => 0.0,
                'avg_win' => 0.0,
                'avg_loss' => 0.0,
                'expectancy' => 0.0,
                'max_drawdown' => 0.0,
            ];
        }

        $wins = $trades->filter(fn (Position $p): bool => $p->unrealized_pnl_percentage > 0);
        $losses = $trades->filter(fn (Position $p): bool => $p->unrealized_pnl_percentage <= 0);

        $winRate = $wins->count() / $count;
        $lossRate = $losses->count() / $count;
        $avgWin = $wins->isNotEmpty()
            ? (float) $wins->avg(fn (Position $p): float => $p->unrealized_pnl_percentage)
            : 0.0;
        $avgLoss = $losses->isNotEmpty()
            ? abs((float) $losses->avg(fn (Position $p): float => $p->unrealized_pnl_percentage))
            : 0.0;

        return [
            'total_trades' => $count,
            'win_rate' => round($winRate * 100, 1),
            'avg_win' => round($avgWin, 2),
            'avg_loss' => round($avgLoss, 2),
            'expectancy' => round(($winRate * $avgWin) - ($lossRate * $avgLoss), 2),
            'max_drawdown' => $this->maxDrawdown($userId),
        ];
    }

    /**
     * @return array<int, array{
     *     tag_id: int,
     *     tag_name: string,
     *     trades: int,
     *     win_rate: float,
     *     expectancy: float,
     * }>
     */
    public function statsPerTag(int $userId): array
    {
        $trades = $this->closedTradesForUser($userId)->filter(
            fn (Position $p): bool => $p->strategy_tag_id !== null,
        );

        $grouped = $trades->groupBy('strategy_tag_id');
        $results = [];

        foreach ($grouped as $tagId => $tagTrades) {
            /** @var Position $first */
            $first = $tagTrades->first();
            $count = $tagTrades->count();
            $wins = $tagTrades->filter(fn (Position $p): bool => $p->unrealized_pnl_percentage > 0);
            $losses = $tagTrades->filter(fn (Position $p): bool => $p->unrealized_pnl_percentage <= 0);

            $winRate = $wins->count() / $count;
            $lossRate = $losses->count() / $count;
            $avgWin = $wins->isNotEmpty()
                ? (float) $wins->avg(fn (Position $p): float => $p->unrealized_pnl_percentage)
                : 0.0;
            $avgLoss = $losses->isNotEmpty()
                ? abs((float) $losses->avg(fn (Position $p): float => $p->unrealized_pnl_percentage))
                : 0.0;

            $results[] = [
                'tag_id' => (int) $tagId,
                'tag_name' => $first->strategyTag?->name ?? 'Onbekend',
                'trades' => $count,
                'win_rate' => round($winRate * 100, 1),
                'expectancy' => round(($winRate * $avgWin) - ($lossRate * $avgLoss), 2),
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['expectancy'] <=> $a['expectancy']);

        return $results;
    }

    /**
     * @return array{best: ?array, worst: ?array}
     */
    public function coachInsight(int $userId): array
    {
        $perTag = $this->statsPerTag($userId);

        if (count($perTag) < 2) {
            return ['best' => $perTag[0] ?? null, 'worst' => null];
        }

        $sorted = collect($perTag)->sortByDesc('win_rate')->values();

        return [
            'best' => $sorted->first(),
            'worst' => $sorted->last(),
        ];
    }

    public function maxDrawdown(int $userId): float
    {
        $curve = $this->equityCurve($userId);

        if (count($curve) < 2) {
            return 0.0;
        }

        $peak = $curve[0]['cumulative_roi'];
        $maxDrawdown = 0.0;

        foreach ($curve as $point) {
            $peak = max($peak, $point['cumulative_roi']);
            $drawdown = $peak - $point['cumulative_roi'];
            $maxDrawdown = max($maxDrawdown, $drawdown);
        }

        return round($maxDrawdown, 2);
    }

    public function profitFactor(int $userId): ?float
    {
        $trades = $this->closedTradesForUser($userId);

        if ($trades->isEmpty()) {
            return null;
        }

        $totalWins = (float) $trades
            ->filter(fn (Position $p): bool => $p->unrealized_pnl > 0)
            ->sum(fn (Position $p): float => $p->unrealized_pnl);
        $totalLosses = abs((float) $trades
            ->filter(fn (Position $p): bool => $p->unrealized_pnl < 0)
            ->sum(fn (Position $p): float => $p->unrealized_pnl));

        if ($totalLosses <= 0) {
            return $totalWins > 0 ? null : 0.0;
        }

        return round($totalWins / $totalLosses, 2);
    }

    /**
     * @return array{
     *     dollars: float,
     *     pct_of_archive_investment: float,
     *     ticker: string,
     * }|null
     */
    public function biggestLoss(int $userId): ?array
    {
        $trades = $this->closedTradesForUser($userId);

        if ($trades->isEmpty()) {
            return null;
        }

        /** @var Position $worst */
        $worst = $trades->sortBy('unrealized_pnl')->first();
        $lossDollars = $worst->unrealized_pnl;

        if ($lossDollars >= 0) {
            return null;
        }

        $archiveInvestment = (float) $trades->sum(fn (Position $p): float => $p->investment);
        $pctOfArchive = $archiveInvestment > 0
            ? round((abs($lossDollars) / $archiveInvestment) * 100, 1)
            : 0.0;

        return [
            'dollars' => $lossDollars,
            'pct_of_archive_investment' => $pctOfArchive,
            'ticker' => $worst->ticker,
        ];
    }

    /**
     * @return array{
     *     hit_rate: float,
     *     hits: int,
     *     total: int,
     *     miss_rate: float,
     * }
     */
    public function freerideHitRate(int $userId): array
    {
        $trades = $this->closedTradesForUser($userId);
        $total = $trades->count();

        if ($total === 0) {
            return [
                'hit_rate' => 0.0,
                'hits' => 0,
                'total' => 0,
                'miss_rate' => 0.0,
            ];
        }

        $hits = $trades->filter(fn (Position $p): bool => $p->freeride_secured_at !== null)->count();
        $hitRate = round(($hits / $total) * 100, 1);

        return [
            'hit_rate' => $hitRate,
            'hits' => $hits,
            'total' => $total,
            'miss_rate' => round(100 - $hitRate, 1),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function activeTags(): array
    {
        return StrategyTag::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (StrategyTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])
            ->all();
    }
}
