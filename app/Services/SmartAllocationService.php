<?php

namespace App\Services;

use App\Contracts\IbkrAccountReader;
use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\User;
use App\Services\Ibkr\IbkrSyncHealth;
use App\Support\PositionSizing;
use Illuminate\Support\Collection;

class SmartAllocationService
{
    public const MODE_EQUAL = 'equal';

    public const MODE_SMART = 'smart';

    public function __construct(
        private readonly IbkrAccountReader $ibkrAccountReader,
        private readonly IbkrSyncHealth $ibkrSyncHealth,
        private readonly PortfolioRiskCoachService $portfolioRiskCoach,
    ) {}

    /**
     * Deployable IBKR capital for sizing: min(Available Funds, Settled Cash).
     * Falls back to NLV when settled/AF are unavailable (manual/stub mode).
     * Returns 0 when IBKR data is stale and automation blocking is enabled.
     */
    public function resolveSizingBankroll(User $user): float
    {
        if ($this->ibkrSyncHealth->blocksAutomatedExecution($user)) {
            return 0.0;
        }

        $available = $this->ibkrAccountReader->availableFunds($user);
        $settled = $this->ibkrAccountReader->settledCash($user);

        if ($available > 0 || $settled > 0) {
            return max(0.0, round(min(
                $available > 0 ? $available : $settled,
                $settled > 0 ? $settled : $available,
            ), 2));
        }

        return max(0.0, round($this->ibkrAccountReader->netLiquidationValue($user), 2));
    }

    /**
     * @param  Collection<int, Position>|iterable<Position>  $positions
     * @return array{
     *     mode: string,
     *     pie: float,
     *     pie_total: float,
     *     pie_committed: float,
     *     pie_percent: float,
     *     pie_breakdown?: array{
     *         long: array{percent: float, total: float, committed: float, available: float},
     *         short?: array{percent: float, total: float, committed: float, available: float},
     *     },
     *     bankroll: float,
     *     weights_uniform: bool,
     *     allocations: list<array{
     *         position_id: int,
     *         ticker: string,
     *         score: int,
     *         reward_risk: float|null,
     *         expected_value: float|null,
     *         sector: string|null,
     *         sector_penalty: float,
     *         weight: float,
     *         weight_share: float,
     *         risk_dollars: float,
     *         risk_percent: float,
     *         quantity: int,
     *         investment: float,
     *         entry: float,
     *         stop_loss: float,
     *         target_1: float|null,
     *     }>,
     *     exclusions: list<array{position_id: int, ticker: string, reason: string}>,
     * }
     */
    public function allocate(User $user, iterable $positions, string $mode = self::MODE_SMART): array
    {
        $mode = $mode === self::MODE_EQUAL ? self::MODE_EQUAL : self::MODE_SMART;
        $bankroll = $this->resolveSizingBankroll($user);
        $longPie = $this->resolvePieBucket($user, $bankroll, TradeDirection::Long);
        $shortPie = $this->resolvePieBucket($user, $bankroll, TradeDirection::Short);
        $includeShortPie = $user->canUseShort();

        $minScore = (int) config('vestix.smart_sizing.min_score', 5);
        $minQuantity = $this->minQuantity();
        $candidatesByDirection = [
            TradeDirection::Long->value => [],
            TradeDirection::Short->value => [],
        ];
        $exclusions = [];
        $sectorExclusions = $this->portfolioRiskCoach->evaluateOrderPlanExclusions($user, $positions);
        $sectorExcludedIds = [];

        foreach ($sectorExclusions as $sectorExclusion) {
            $exclusions[] = $sectorExclusion;
            $sectorExcludedIds[(int) $sectorExclusion['position_id']] = true;
        }

        $openRiskOnByDirection = $this->portfolioRiskCoach->openRiskOnSectorDirectionCounts($user);

        foreach ($positions as $position) {
            if (! $position instanceof Position) {
                continue;
            }

            $ticker = (string) $position->ticker;
            $entry = $position->entry_price !== null ? (float) $position->entry_price : null;
            $stopLoss = $position->new_sl !== null ? (float) $position->new_sl : null;
            $score = $this->resolveScore($position);
            $directionKey = $position->isShort()
                ? TradeDirection::Short->value
                : TradeDirection::Long->value;
            $availablePie = $position->isShort() ? $shortPie['available'] : $longPie['available'];

            if (isset($sectorExcludedIds[(int) $position->id])) {
                continue;
            }

            if ($position->isOrderPlanExcludedToday()) {
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => sprintf(
                        'Uitgesloten vandaag (min. %d aandelen paste niet) — niet opnieuw verdeeld',
                        $minQuantity,
                    ),
                ];

                continue;
            }

            if ($score < $minScore) {
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => "Score {$score} < {$minScore} (C of lager)",
                ];

                continue;
            }

            if ($entry === null || $entry <= 0 || $stopLoss === null) {
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => 'Entry of stop-loss ontbreekt',
                ];

                continue;
            }

            $riskPerShare = PositionSizing::riskPerShare($entry, $stopLoss, $position->tradeDirection());

            if ($riskPerShare === null) {
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => $position->isShort()
                        ? 'Stop-loss ligt op of onder entry'
                        : 'Stop-loss ligt op of boven entry',
                ];

                continue;
            }

            $minRiskForLot = $riskPerShare * $minQuantity;

            if ($availablePie > 0 && $minRiskForLot > $availablePie) {
                $position->markOrderPlanExcludedToday();
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => sprintf(
                        'Min. %d aandelen kost $%s risico > beschikbare pie $%s — past niet',
                        $minQuantity,
                        number_format($minRiskForLot, 2),
                        number_format($availablePie, 2),
                    ),
                ];

                continue;
            }

            $target1 = $position->plannedBracketTarget1Price();
            $rewardRisk = null;

            if ($target1 !== null) {
                if ($position->isShort() && $target1 < $entry) {
                    $rewardRisk = ($entry - $target1) / $riskPerShare;
                } elseif (! $position->isShort() && $target1 > $entry) {
                    $rewardRisk = ($target1 - $entry) / $riskPerShare;
                }
            }

            $sector = filled($position->sector_etf)
                ? strtoupper((string) $position->sector_etf)
                : null;

            $candidatesByDirection[$directionKey][] = [
                'position' => $position,
                'ticker' => $ticker,
                'score' => $score,
                'entry' => $entry,
                'stop_loss' => $stopLoss,
                'target_1' => $target1,
                'reward_risk' => $rewardRisk,
                'sector' => $sector,
                'risk_per_share' => $riskPerShare,
            ];
        }

        $allocations = [];

        foreach ($candidatesByDirection as $directionKey => $candidates) {
            if ($candidates === []) {
                continue;
            }

            $availablePie = $directionKey === TradeDirection::Short->value
                ? $shortPie['available']
                : $longPie['available'];

            if ($availablePie <= 0) {
                continue;
            }

            $directionSeed = [];

            foreach ($openRiskOnByDirection as $sector => $counts) {
                $count = (int) ($counts[$directionKey] ?? 0);

                if ($count > 0) {
                    $directionSeed[$sector] = $count;
                }
            }

            $allocations = [
                ...$allocations,
                ...$this->allocateWithRedistribution(
                    $candidates,
                    $availablePie,
                    $bankroll,
                    $mode,
                    $exclusions,
                    $directionSeed,
                ),
            ];
        }

        usort($allocations, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        $pieTotal = $longPie['total'] + ($includeShortPie ? $shortPie['total'] : 0.0);
        $pieCommitted = $longPie['committed'] + ($includeShortPie ? $shortPie['committed'] : 0.0);
        $pieAvailable = $longPie['available'] + ($includeShortPie ? $shortPie['available'] : 0.0);
        $pieBreakdown = ['long' => $longPie];

        if ($includeShortPie) {
            $pieBreakdown['short'] = $shortPie;
        }

        return [
            'mode' => $mode,
            'pie' => round($pieAvailable, 2),
            'pie_total' => round($pieTotal, 2),
            'pie_committed' => round($pieCommitted, 2),
            'pie_percent' => $longPie['percent'],
            'pie_breakdown' => $pieBreakdown,
            'bankroll' => $bankroll,
            'weights_uniform' => $this->weightsAreUniform($allocations),
            'allocations' => $allocations,
            'exclusions' => $exclusions,
        ];
    }

    /**
     * @param  list<array{
     *     position: Position,
     *     ticker: string,
     *     score: int,
     *     entry: float,
     *     stop_loss: float,
     *     target_1: float|null,
     *     reward_risk: float|null,
     *     sector: string|null,
     *     risk_per_share: float,
     * }>  $candidates
     * @param  list<array{position_id: int, ticker: string, reason: string}>  $exclusions
     * @param  array<string, int>  $openRiskOnSectorCounts
     * @return list<array{
     *     position_id: int,
     *     ticker: string,
     *     score: int,
     *     reward_risk: float|null,
     *     expected_value: float|null,
     *     sector: string|null,
     *     sector_penalty: float,
     *     weight: float,
     *     weight_share: float,
     *     risk_dollars: float,
     *     risk_percent: float,
     *     quantity: int,
     *     investment: float,
     *     entry: float,
     *     stop_loss: float,
     *     target_1: float|null,
     * }>
     */
    private function allocateWithRedistribution(
        array $candidates,
        float $pie,
        float $bankroll,
        string $mode,
        array &$exclusions,
        array $openRiskOnSectorCounts = [],
    ): array {
        $working = $candidates;
        $allocations = [];
        $maxRounds = count($working) + 1;
        $minQuantity = $this->minQuantity();

        while ($working !== [] && $maxRounds-- > 0) {
            $round = $this->allocateAmong($working, $pie, $bankroll, $mode, $openRiskOnSectorCounts);

            if ($round === []) {
                return [];
            }

            $affordable = [];
            $droppedIds = [];

            foreach ($round as $row) {
                if ($row['quantity'] >= $minQuantity) {
                    $affordable[] = $row;

                    continue;
                }

                $positionId = (int) $row['position_id'];
                $droppedIds[$positionId] = true;

                $dropped = collect($working)->first(
                    fn (array $candidate): bool => (int) $candidate['position']->id === $positionId,
                );

                if (is_array($dropped) && $dropped['position'] instanceof Position) {
                    $dropped['position']->markOrderPlanExcludedToday();
                }

                $exclusions[] = [
                    'position_id' => $positionId,
                    'ticker' => $row['ticker'],
                    'reason' => sprintf(
                        'Min. %d aandelen kost $%s risico; toegekend $%s — budget herverdeeld',
                        $minQuantity,
                        number_format(abs($row['entry'] - $row['stop_loss']) * $minQuantity, 2),
                        number_format($row['risk_dollars'], 2),
                    ),
                ];
            }

            $allocations = $affordable;

            if ($droppedIds === []) {
                break;
            }

            $working = array_values(array_filter(
                $working,
                fn (array $candidate): bool => ! isset($droppedIds[(int) $candidate['position']->id]),
            ));
        }

        return $allocations;
    }

    /**
     * @return array{percent: float, total: float, committed: float, available: float}
     */
    private function resolvePieBucket(User $user, float $bankroll, TradeDirection $direction): array
    {
        $percent = $user->defaultRiskPercentFor($direction);
        $total = ($bankroll > 0 && $percent > 0)
            ? PositionSizing::riskBudgetFromPercent($bankroll, $percent)
            : 0.0;
        $committed = $this->committedActiveRisk($user, $direction);

        return [
            'percent' => $percent,
            'total' => round($total, 2),
            'committed' => round($committed, 2),
            'available' => max(0.0, round($total - $committed, 2)),
        ];
    }

    /**
     * Risk already reserved by live buy-stops (Actief vandaag).
     * Uses planned risk from qty × (entry − stop), falling back to stored risk_budget.
     */
    public function committedActiveRisk(User $user, ?TradeDirection $direction = null): float
    {
        $positions = Position::activeOrderPlanForUser((int) $user->id);

        if ($direction !== null) {
            $positions = $positions->filter(
                fn (Position $position): bool => $direction === TradeDirection::Short
                    ? $position->isShort()
                    : ! $position->isShort(),
            );
        }

        return round($positions->sum(
            function (Position $position): float {
                $planned = $position->planned_risk_dollars;

                if ($planned !== null && $planned > 0) {
                    return (float) $planned;
                }

                if ($position->risk_budget !== null && (float) $position->risk_budget > 0) {
                    return (float) $position->risk_budget;
                }

                return 0.0;
            },
        ), 2);
    }

    /**
     * @param  list<array{
     *     position: Position,
     *     ticker: string,
     *     score: int,
     *     entry: float,
     *     stop_loss: float,
     *     target_1: float|null,
     *     reward_risk: float|null,
     *     sector: string|null,
     *     risk_per_share: float,
     * }>  $candidates
     * @param  array<string, int>  $openRiskOnSectorCounts
     * @return list<array{
     *     position_id: int,
     *     ticker: string,
     *     score: int,
     *     reward_risk: float|null,
     *     expected_value: float|null,
     *     sector: string|null,
     *     sector_penalty: float,
     *     weight: float,
     *     weight_share: float,
     *     risk_dollars: float,
     *     risk_percent: float,
     *     quantity: int,
     *     investment: float,
     *     entry: float,
     *     stop_loss: float,
     *     target_1: float|null,
     * }>
     */
    private function allocateAmong(
        array $candidates,
        float $pie,
        float $bankroll,
        string $mode,
        array $openRiskOnSectorCounts = [],
    ): array {
        $sectorCounts = $openRiskOnSectorCounts;

        foreach ($candidates as $candidate) {
            if ($candidate['sector'] === null) {
                continue;
            }

            $sectorCounts[$candidate['sector']] = ($sectorCounts[$candidate['sector']] ?? 0) + 1;
        }

        $penaltyPerExtra = (float) config('vestix.smart_sizing.sector_penalty_per_extra', 0.20);
        $penaltyCap = (float) config('vestix.smart_sizing.sector_penalty_cap', 0.90);

        $weighted = [];

        foreach ($candidates as $candidate) {
            $sectorPenalty = 0.0;

            if ($candidate['sector'] !== null) {
                $count = $sectorCounts[$candidate['sector']] ?? 1;

                if ($count >= 2) {
                    $sectorPenalty = min(($count - 1) * $penaltyPerExtra, $penaltyCap);
                }
            }

            if ($mode === self::MODE_EQUAL) {
                $weight = 1.0;
                $expectedValue = null;
            } else {
                $rr = $candidate['reward_risk'] ?? (float) config('vestix.scale_out.target_1_rr', 2.0);
                $expectedValue = $candidate['score'] * max($rr, 0.0);
                $weight = $expectedValue * (1 - $sectorPenalty);
            }

            $weighted[] = [
                ...$candidate,
                'expected_value' => $expectedValue,
                'sector_penalty' => $sectorPenalty,
                'weight' => max($weight, 0.0),
            ];
        }

        $totalWeight = array_sum(array_column($weighted, 'weight'));

        if ($totalWeight <= 0) {
            return [];
        }

        $allocations = [];

        foreach ($weighted as $row) {
            $share = $row['weight'] / $totalWeight;
            $riskDollars = min($pie * $share, $pie);
            $quantity = PositionSizing::quantityFromRiskBudget(
                $riskDollars,
                $row['entry'],
                $row['stop_loss'],
                $row['position']->tradeDirection(),
            ) ?? 0;
            $investment = $quantity * $row['entry'];
            $riskPercent = $bankroll > 0
                ? PositionSizing::riskAsPercentOfBankroll($riskDollars, $bankroll)
                : 0.0;

            $allocations[] = [
                'position_id' => (int) $row['position']->id,
                'ticker' => $row['ticker'],
                'score' => $row['score'],
                'reward_risk' => $row['reward_risk'],
                'expected_value' => $row['expected_value'],
                'sector' => $row['sector'],
                'sector_penalty' => $row['sector_penalty'],
                'weight' => $row['weight'],
                'weight_share' => $share,
                'risk_dollars' => round($riskDollars, 2),
                'risk_percent' => round($riskPercent, 4),
                'quantity' => $quantity,
                'investment' => round($investment, 2),
                'entry' => $row['entry'],
                'stop_loss' => $row['stop_loss'],
                'target_1' => $row['target_1'],
            ];
        }

        return $allocations;
    }

    /**
     * @param  list<array{position_id: int, quantity: int, risk_dollars: float, risk_percent: float}>  $allocations
     * @param  Collection<int, Position>|iterable<Position>  $positions
     */
    public function applyToPositions(iterable $positions, array $allocations): int
    {
        $byId = [];

        foreach ($allocations as $allocation) {
            $byId[(int) $allocation['position_id']] = $allocation;
        }

        $updated = 0;

        foreach ($positions as $position) {
            if (! $position instanceof Position) {
                continue;
            }

            $allocation = $byId[(int) $position->id] ?? null;

            if ($allocation === null) {
                continue;
            }

            $position->update([
                'quantity' => $allocation['quantity'],
                'risk_budget' => $allocation['risk_dollars'],
                'risk_percent' => $allocation['risk_percent'],
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * @param  list<array{weight_share: float}>  $allocations
     */
    private function weightsAreUniform(array $allocations): bool
    {
        if (count($allocations) <= 1) {
            return true;
        }

        $shares = array_column($allocations, 'weight_share');
        $first = (float) $shares[0];

        foreach ($shares as $share) {
            if (abs((float) $share - $first) > 0.001) {
                return false;
            }
        }

        return true;
    }

    private function resolveScore(Position $position): int
    {
        if ($this->scorecardDataIncomplete($position)) {
            return (int) ($position->last_setup_score ?? 0);
        }

        return (int) $position->evaluateSetupScore()['totalPoints'];
    }

    private function minQuantity(): int
    {
        return max(1, (int) config('vestix.smart_sizing.min_quantity', 2));
    }

    private function scorecardDataIncomplete(Position $position): bool
    {
        $hasSignalAnchor = $position->isShort()
            ? $position->signal_high !== null
            : $position->signal_low !== null;

        return ! $hasSignalAnchor
            || $position->latest_sma_20 === null
            || $position->scout_rsi === null
            || $position->latest_close_price === null;
    }
}
