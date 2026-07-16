<?php

namespace App\Services;

use App\Enums\Broker;
use App\Models\Position;
use App\Models\User;
use App\Services\Bankroll\IbkrBankrollSource;
use App\Support\PositionSizing;
use Illuminate\Support\Collection;

class SmartAllocationService
{
    public const MODE_EQUAL = 'equal';

    public const MODE_SMART = 'smart';

    public function __construct(
        private readonly IbkrBankrollSource $ibkrBankrollSource,
    ) {}

    /**
     * IBKR Net Liquidation for sizing — excludes open Revolut / legacy position value
     * when that capital is still folded into a manual bankroll total.
     */
    public function resolveSizingBankroll(User $user): float
    {
        $nlv = $this->ibkrBankrollSource->resolveAmount($user);
        $nonIbkrValue = $this->nonIbkrOpenPositionValue($user);

        return max(0.0, round($nlv - $nonIbkrValue, 2));
    }

    /**
     * @param  Collection<int, Position>|iterable<Position>  $positions
     * @return array{
     *     mode: string,
     *     pie: float,
     *     pie_percent: float,
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
        $piePercent = (float) ($user->default_risk_percent ?? 1);
        $pie = ($bankroll > 0 && $piePercent > 0)
            ? PositionSizing::riskBudgetFromPercent($bankroll, $piePercent)
            : 0.0;

        $minScore = (int) config('vestix.smart_sizing.min_score', 5);
        $candidates = [];
        $exclusions = [];

        foreach ($positions as $position) {
            if (! $position instanceof Position) {
                continue;
            }

            $ticker = (string) $position->ticker;
            $entry = $position->entry_price !== null ? (float) $position->entry_price : null;
            $stopLoss = $position->new_sl !== null ? (float) $position->new_sl : null;
            $score = $this->resolveScore($position);

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

            $riskPerShare = round($entry - $stopLoss, 2);

            if ($riskPerShare <= 0) {
                $exclusions[] = [
                    'position_id' => (int) $position->id,
                    'ticker' => $ticker,
                    'reason' => 'Stop-loss ligt op of boven entry',
                ];

                continue;
            }

            $target1 = $position->plannedBracketTarget1Price();
            $rewardRisk = null;

            if ($target1 !== null && $target1 > $entry) {
                $rewardRisk = ($target1 - $entry) / $riskPerShare;
            }

            $sector = filled($position->sector_etf)
                ? strtoupper((string) $position->sector_etf)
                : null;

            $candidates[] = [
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

        if ($candidates === [] || $pie <= 0) {
            return [
                'mode' => $mode,
                'pie' => $pie,
                'pie_percent' => $piePercent,
                'bankroll' => $bankroll,
                'weights_uniform' => true,
                'allocations' => [],
                'exclusions' => $exclusions,
            ];
        }

        $sectorCounts = [];

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
            return [
                'mode' => $mode,
                'pie' => $pie,
                'pie_percent' => $piePercent,
                'bankroll' => $bankroll,
                'weights_uniform' => true,
                'allocations' => [],
                'exclusions' => $exclusions,
            ];
        }

        $allocations = [];

        foreach ($weighted as $row) {
            $share = $row['weight'] / $totalWeight;
            $riskDollars = min($pie * $share, $pie);
            $quantity = PositionSizing::quantityFromRiskBudget($riskDollars, $row['entry'], $row['stop_loss']) ?? 0;
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

        usort($allocations, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'mode' => $mode,
            'pie' => round($pie, 2),
            'pie_percent' => $piePercent,
            'bankroll' => $bankroll,
            'weights_uniform' => $this->weightsAreUniform($allocations),
            'allocations' => $allocations,
            'exclusions' => $exclusions,
        ];
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

    private function nonIbkrOpenPositionValue(User $user): float
    {
        $positions = Position::query()
            ->forUser((int) $user->id)
            ->open()
            ->where(function ($query): void {
                $query->where('is_legacy', true)
                    ->orWhere('broker', Broker::Revolut->value);
            })
            ->get();

        $total = 0.0;

        foreach ($positions as $position) {
            $total += (float) $position->current_value;
        }

        return round($total, 2);
    }

    private function resolveScore(Position $position): int
    {
        if ($position->last_setup_score !== null) {
            return (int) $position->last_setup_score;
        }

        return (int) $position->evaluateSetupScore()['totalPoints'];
    }
}
