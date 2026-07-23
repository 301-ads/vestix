<?php

namespace App\Support;

use App\Enums\ScoutPipelineStatus;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;

class ScoutRadarFilters
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'ready' => 'Klaar voor executie',
            'gap_up' => 'Gap-up risico',
            'reclamation' => 'Reclamation PM',
            'landing' => 'Landing PM',
            'strong_setups' => 'Sterke setups (A/A++)',
            'a_plus' => 'Top setups (A+)',
            'scout_only' => 'Status: Pending',
            'market_open_pending' => 'Status: Reminder (market open)',
            'active_only' => 'Status: Active (order live)',
            'pending_only' => 'Status: Active (order live)',
            'review_required' => 'Status: Review vereist',
            'premarket_signals' => 'Pre-market signalen',
            'execution_pipeline' => 'Executie (live + reminder)',
            'track_a' => 'Track A — Landing',
            'track_b' => 'Track B — Reclamation',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function dashboardOptions(): array
    {
        return [
            'strong_setups' => 'Sterke setups (A+/A-)',
            'a_plus' => 'Alleen A+',
            'ready' => 'Klaar voor executie',
        ];
    }

    public static function indicatorLabel(?string $focus): ?string
    {
        if (blank($focus)) {
            return null;
        }

        return self::options()[$focus] ?? null;
    }

    public static function matches(Position $scout, string $focus): bool
    {
        return match ($focus) {
            'ready' => self::isReadyForExecution($scout),
            'gap_up' => $scout->hasPremarketGapUpRisk(),
            'reclamation' => $scout->hasPremarketReclamation(),
            'landing' => $scout->hasPremarketLanding(),
            'strong_setups' => self::isStrongSetup($scout),
            'a_plus' => self::isAPlusSetup($scout),
            'scout_only' => self::pipelineStatus($scout) === ScoutPipelineStatus::Scout,
            'market_open_pending' => self::pipelineStatus($scout) === ScoutPipelineStatus::Pending,
            'active_only', 'pending_only' => self::pipelineStatus($scout) === ScoutPipelineStatus::Active,
            'review_required' => self::pipelineStatus($scout) === ScoutPipelineStatus::ReviewRequired,
            'premarket_signals' => self::hasPremarketSignal($scout),
            'execution_pipeline' => self::isInExecutionPipeline($scout),
            'track_a' => $scout->hasPremarketLanding(),
            'track_b' => $scout->hasPremarketReclamation(),
            default => false,
        };
    }

    public static function apply(Builder $query, ?string $focus): Builder
    {
        if (blank($focus)) {
            return $query;
        }

        $ids = (clone $query)->pluck('id');

        if ($ids->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $matchingIds = Position::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Position $scout): bool => self::matches($scout, $focus))
            ->pluck('id');

        if ($matchingIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $matchingIds);
    }

    public static function riskColor(?float $percentage): string
    {
        return match (true) {
            $percentage === null => 'gray',
            $percentage < 4 => 'success',
            $percentage <= 6 => 'warning',
            default => 'danger',
        };
    }

    public static function trackLabel(Position $scout): ?string
    {
        if ($scout->hasPremarketReclamation()) {
            return 'B';
        }

        if ($scout->hasPremarketLanding()) {
            return 'A';
        }

        return null;
    }

    public static function trackColor(Position $scout): string
    {
        return match (self::trackLabel($scout)) {
            'A' => 'warning',
            'B' => 'success',
            default => 'gray',
        };
    }

    /**
     * Absolute distance from latest close to planned entry, as a percentage of entry.
     */
    public static function entryDistancePercent(Position $scout): ?float
    {
        if ($scout->entry_price === null || $scout->latest_close_price === null || (float) $scout->entry_price <= 0) {
            return null;
        }

        return (abs((float) $scout->latest_close_price - (float) $scout->entry_price) / (float) $scout->entry_price) * 100;
    }

    public static function entryDistanceLabel(Position $scout): ?string
    {
        $percent = self::entryDistancePercent($scout);

        if ($percent === null) {
            return null;
        }

        return '−'.number_format($percent, 2).'%';
    }

    public static function entryDistanceColor(Position $scout): string
    {
        $percent = self::entryDistancePercent($scout);

        if ($percent === null) {
            return 'gray';
        }

        return $percent <= 1.0 ? 'success' : 'gray';
    }

    private static function isReadyForExecution(Position $scout): bool
    {
        $distance = self::entryDistancePercent($scout);

        if ($distance === null || $distance > 1.0) {
            return false;
        }

        return self::isMinimumTradeableGrade($scout);
    }

    private static function isMinimumTradeableGrade(Position $scout): bool
    {
        if (! self::hasCompleteSetupData($scout)) {
            return false;
        }

        $score = $scout->evaluateSetupScore();

        return $score['hardFailReasons'] === []
            && in_array($score['grade'], ['A++', 'A', 'B'], true);
    }

    private static function isAPlusSetup(Position $scout): bool
    {
        if (! self::hasCompleteSetupData($scout)) {
            return false;
        }

        return $scout->evaluateSetupScore()['grade'] === 'A++';
    }

    private static function isStrongSetup(Position $scout): bool
    {
        if (! self::hasCompleteSetupData($scout)) {
            return false;
        }

        return in_array($scout->evaluateSetupScore()['grade'], ['A++', 'A'], true);
    }

    private static function hasCompleteSetupData(Position $scout): bool
    {
        return ! (
            ($scout->signal_low === null && $scout->latest_close_price === null)
            || $scout->latest_sma_20 === null
            || $scout->scout_rsi === null
        );
    }

    private static function hasPremarketSignal(Position $scout): bool
    {
        return $scout->hasPremarketGapUpRisk()
            || $scout->hasPremarketReclamation()
            || $scout->hasPremarketLanding();
    }

    private static function pipelineStatus(Position $scout): ScoutPipelineStatus
    {
        return ScoutPipelineStatus::fromPosition($scout);
    }

    private static function isInExecutionPipeline(Position $scout): bool
    {
        return in_array(self::pipelineStatus($scout), [
            ScoutPipelineStatus::Pending,
            ScoutPipelineStatus::Active,
            ScoutPipelineStatus::ReviewRequired,
        ], true);
    }
}
