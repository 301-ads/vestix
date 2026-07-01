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
            'strong_setups' => 'Sterke setups (A+/A-)',
            'a_plus' => 'Top setups (A+)',
            'scout_only' => 'Status: Scout',
            'market_open_pending' => 'Status: Pending (market open)',
            'active_only' => 'Status: Active (order live)',
            'pending_only' => 'Status: Active (order live)',
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

    private static function isReadyForExecution(Position $scout): bool
    {
        if ($scout->entry_price === null || $scout->latest_close_price === null || (float) $scout->entry_price <= 0) {
            return false;
        }

        $distance = abs((float) $scout->latest_close_price - (float) $scout->entry_price) / (float) $scout->entry_price;

        return $distance <= 0.01;
    }

    private static function isAPlusSetup(Position $scout): bool
    {
        if (! self::hasCompleteSetupData($scout)) {
            return false;
        }

        return $scout->evaluateSetupScore()['grade'] === 'A+';
    }

    private static function isStrongSetup(Position $scout): bool
    {
        if (! self::hasCompleteSetupData($scout)) {
            return false;
        }

        return in_array($scout->evaluateSetupScore()['grade'], ['A+', 'A-'], true);
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
        ], true);
    }
}
