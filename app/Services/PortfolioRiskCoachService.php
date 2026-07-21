<?php

namespace App\Services;

use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;

class PortfolioRiskCoachService
{
    public function maxRiskOnPerSector(): int
    {
        return max(1, (int) config('vestix.portfolio_coach.max_risk_on_per_sector', 1));
    }

    /**
     * @return Collection<int, Position>
     */
    public function openPositions(User $user): Collection
    {
        return Position::query()
            ->open()
            ->nonLegacy()
            ->forUser((int) $user->id)
            ->orderBy('ticker')
            ->get();
    }

    /**
     * @return array<string, array{
     *     sector: string,
     *     risk_on: list<string>,
     *     locked: list<string>,
     *     risk_on_count: int,
     *     locked_count: int,
     * }>
     */
    public function sectorExposure(User $user): array
    {
        $exposure = [];

        foreach ($this->openPositions($user) as $position) {
            $sector = $this->normalizeSector($position->sector_etf);

            if ($sector === null) {
                continue;
            }

            if (! isset($exposure[$sector])) {
                $exposure[$sector] = [
                    'sector' => $sector,
                    'risk_on' => [],
                    'locked' => [],
                    'risk_on_count' => 0,
                    'locked_count' => 0,
                ];
            }

            $ticker = strtoupper((string) $position->ticker);

            if ($this->isRiskOn($position)) {
                $exposure[$sector]['risk_on'][] = $ticker;
                $exposure[$sector]['risk_on_count']++;
            } else {
                $exposure[$sector]['locked'][] = $ticker;
                $exposure[$sector]['locked_count']++;
            }
        }

        ksort($exposure);

        return $exposure;
    }

    /**
     * Open risk-on count per sector ETF (for allocation seeding / limits).
     *
     * @return array<string, int>
     */
    public function openRiskOnSectorCounts(User $user): array
    {
        $counts = [];

        foreach ($this->sectorExposure($user) as $sector => $row) {
            if ($row['risk_on_count'] > 0) {
                $counts[$sector] = $row['risk_on_count'];
            }
        }

        return $counts;
    }

    /**
     * @return array{
     *     total: int,
     *     long: int,
     *     short: int,
     *     long_pct: float,
     *     short_pct: float,
     * }
     */
    public function longShortBalance(User $user): array
    {
        $open = $this->openPositions($user);
        $long = $open->filter(fn (Position $p): bool => ! $p->isShort())->count();
        $short = $open->filter(fn (Position $p): bool => $p->isShort())->count();
        $total = $long + $short;

        return [
            'total' => $total,
            'long' => $long,
            'short' => $short,
            'long_pct' => $total > 0 ? $long / $total : 0.0,
            'short_pct' => $total > 0 ? $short / $total : 0.0,
        ];
    }

    /**
     * Deterministic portfolio advisories for the Vestix Coach UI.
     *
     * @return list<array{type: string, severity: string, title: string, body: string}>
     */
    public function insights(User $user): array
    {
        $insights = [];
        $exposure = $this->sectorExposure($user);
        $maxRiskOn = $this->maxRiskOnPerSector();
        $balance = $this->longShortBalance($user);
        $open = $this->openPositions($user);

        foreach ($exposure as $sector => $row) {
            if ($row['risk_on_count'] < $maxRiskOn) {
                continue;
            }

            $tickers = implode(', ', $row['risk_on']);
            $insights[] = [
                'type' => 'sector_concentration',
                'severity' => 'warning',
                'title' => "Sector {$sector} vol",
                'body' => sprintf(
                    'Je hebt %d risk-on positie(s) in %s (%s). Voeg geen nieuwe setups in deze sector toe.',
                    $row['risk_on_count'],
                    $sector,
                    $tickers,
                ),
            ];
        }

        $longHeavy = (float) config('vestix.portfolio_coach.long_heavy_threshold', 0.80);
        $shortHeavy = (float) config('vestix.portfolio_coach.short_heavy_threshold', 0.80);

        if ($balance['total'] >= 2 && $balance['long_pct'] >= $longHeavy && $user->canUseShort()) {
            $insights[] = [
                'type' => 'long_heavy',
                'severity' => 'info',
                'title' => 'Long/short balans',
                'body' => sprintf(
                    'Portfolio: %d%% long (%d/%d). Overweeg een A+ short-setup voor balans.',
                    (int) round($balance['long_pct'] * 100),
                    $balance['long'],
                    $balance['total'],
                ),
            ];
        } elseif ($balance['total'] >= 2 && $balance['short_pct'] >= $shortHeavy) {
            $insights[] = [
                'type' => 'short_heavy',
                'severity' => 'info',
                'title' => 'Long/short balans',
                'body' => sprintf(
                    'Portfolio: %d%% short (%d/%d). Overweeg een A+ long-setup voor balans.',
                    (int) round($balance['short_pct'] * 100),
                    $balance['short'],
                    $balance['total'],
                ),
            ];
        }

        $lockedCount = $open->filter(fn (Position $p): bool => ! $this->isRiskOn($p))->count();
        $occupiedSectors = array_keys($exposure);
        $meewindSuggestions = $this->meewindSectorSuggestions($user, $occupiedSectors);

        if ($lockedCount >= 2 && $meewindSuggestions !== []) {
            $insights[] = [
                'type' => 'free_ammo',
                'severity' => 'success',
                'title' => 'Vrije munitie',
                'body' => sprintf(
                    '%d runners risk-free. Geen open exposure in %s (meewind) — kandidaat voor scan.',
                    $lockedCount,
                    implode(', ', array_slice($meewindSuggestions, 0, 3)),
                ),
            ];
        } elseif ($meewindSuggestions !== [] && $open->isNotEmpty()) {
            $insights[] = [
                'type' => 'empty_meewind',
                'severity' => 'info',
                'title' => 'Sectorkansen',
                'body' => sprintf(
                    'Meewind zonder open exposure: %s. Overweeg daar te scannen.',
                    implode(', ', array_slice($meewindSuggestions, 0, 3)),
                ),
            ];
        }

        if ($insights === [] && $open->isNotEmpty()) {
            $insights[] = [
                'type' => 'balanced',
                'severity' => 'success',
                'title' => 'Portfolio in balans',
                'body' => sprintf(
                    '%d open positie(s), %d long / %d short. Geen correlatie- of balanswaarschuwing.',
                    $balance['total'],
                    $balance['long'],
                    $balance['short'],
                ),
            ];
        } elseif ($insights === []) {
            $insights[] = [
                'type' => 'empty',
                'severity' => 'gray',
                'title' => 'Geen open posities',
                'body' => 'Zodra je trades openstaan, bewaakt Vestix Coach sector-concentratie en long/short-balans.',
            ];
        }

        return $insights;
    }

    /**
     * Soft-exclude Order Plan scouts that would breach sector risk-on limits.
     * Marks excluded scouts via order_plan_excluded_on.
     *
     * @param  Collection<int, Position>|iterable<Position>  $scouts
     * @return list<array{position_id: int, ticker: string, reason: string}>
     */
    public function evaluateOrderPlanExclusions(User $user, iterable $scouts): array
    {
        $maxRiskOn = $this->maxRiskOnPerSector();
        $openRiskOn = $this->openRiskOnSectorCounts($user);
        $openExposure = $this->sectorExposure($user);

        /** @var array<string, list<Position>> $bySector */
        $bySector = [];

        foreach ($scouts as $scout) {
            if (! $scout instanceof Position) {
                continue;
            }

            $sector = $this->normalizeSector($scout->sector_etf);

            if ($sector === null) {
                continue;
            }

            $bySector[$sector][] = $scout;
        }

        $exclusions = [];

        foreach ($bySector as $sector => $sectorScouts) {
            $openCount = $openRiskOn[$sector] ?? 0;
            $remainingSlots = max(0, $maxRiskOn - $openCount);

            usort(
                $sectorScouts,
                function (Position $a, Position $b): int {
                    $scoreCmp = $this->resolveScoutScore($b) <=> $this->resolveScoutScore($a);

                    if ($scoreCmp !== 0) {
                        return $scoreCmp;
                    }

                    return strcmp((string) $a->ticker, (string) $b->ticker);
                },
            );

            if ($remainingSlots === 0) {
                $openTickers = $openExposure[$sector]['risk_on'] ?? [];
                $openLabel = $openTickers !== []
                    ? implode(', ', $openTickers)
                    : 'open risk-on';

                foreach ($sectorScouts as $scout) {
                    $exclusions[] = $this->excludeScout(
                        $scout,
                        sprintf(
                            'Sector %s: al %d risk-on open (%s) — correlatierisico',
                            $sector,
                            $openCount,
                            $openLabel,
                        ),
                    );
                }

                continue;
            }

            $keepers = array_slice($sectorScouts, 0, $remainingSlots);
            $dropped = array_slice($sectorScouts, $remainingSlots);

            if ($dropped === []) {
                continue;
            }

            $keeperTickers = array_map(
                fn (Position $p): string => strtoupper((string) $p->ticker),
                $keepers,
            );

            foreach ($dropped as $scout) {
                $exclusions[] = $this->excludeScout(
                    $scout,
                    sprintf(
                        'Sector %s: max %d nieuwe setup(s); behouden: %s',
                        $sector,
                        $remainingSlots,
                        implode(', ', $keeperTickers),
                    ),
                );
            }
        }

        return $exclusions;
    }

    public function isRiskOn(Position $position): bool
    {
        return $position->status === 'open' && $position->capital_risk_dollars > 0;
    }

    public function normalizeSector(mixed $sector): ?string
    {
        $value = strtoupper(trim((string) $sector));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $occupiedSectors
     * @return list<string>
     */
    private function meewindSectorSuggestions(User $user, array $occupiedSectors): array
    {
        $occupied = array_fill_keys($occupiedSectors, true);
        $suggestions = [];

        $scouts = Position::query()
            ->scout()
            ->nonLegacy()
            ->forUser((int) $user->id)
            ->where('sector_trend_positive', true)
            ->whereNotNull('sector_etf')
            ->get(['sector_etf']);

        foreach ($scouts as $scout) {
            $sector = $this->normalizeSector($scout->sector_etf);

            if ($sector === null || isset($occupied[$sector])) {
                continue;
            }

            $suggestions[$sector] = true;
        }

        $list = array_keys($suggestions);
        sort($list);

        return $list;
    }

    /**
     * @return array{position_id: int, ticker: string, reason: string}
     */
    private function excludeScout(Position $scout, string $reason): array
    {
        $scout->markOrderPlanExcludedToday();

        return [
            'position_id' => (int) $scout->id,
            'ticker' => strtoupper((string) $scout->ticker),
            'reason' => $reason,
        ];
    }

    private function resolveScoutScore(Position $position): int
    {
        return (int) ($position->last_setup_score ?? 0);
    }
}
