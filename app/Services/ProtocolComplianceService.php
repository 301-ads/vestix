<?php

namespace App\Services;

use App\Models\Position;

class ProtocolComplianceService
{
    /**
     * Score how well the trader followed Vestix protocol on a closed trade.
     *
     * @return array{score: int, max: int, details: array<string, bool>, label: string}
     */
    public function score(Position $position): array
    {
        $checks = [
            'initial_sl_placed' => $position->initial_sl_placed_at !== null,
            'target_1_or_freeride' => $position->hasScaledOut()
                || $position->freeride_secured_at !== null
                || $position->hasTarget1LimitPlaced(),
            'breakeven_after_scale' => ! $position->hasScaledOut()
                || $this->stopAtOrBeyondBreakeven($position),
            'journal_present' => filled($position->trade_journal)
                || filled($position->exit_chart_screenshot_path),
        ];

        $score = count(array_filter($checks));
        $max = count($checks);
        $pct = $max > 0 ? (int) round(($score / $max) * 100) : 0;

        return [
            'score' => $pct,
            'max' => 100,
            'details' => $checks,
            'label' => match (true) {
                $pct >= 75 => 'Sterk protocol',
                $pct >= 50 => 'Gedeeltelijk',
                default => 'Protocol zwak',
            },
        ];
    }

    public function persistForClosed(Position $position): void
    {
        if ($position->status !== 'closed') {
            return;
        }

        $result = $this->score($position);

        $position->forceFill([
            'protocol_score' => $result['score'],
            'protocol_score_details' => $result['details'],
        ])->save();
    }

    /**
     * @return array{avg_score: float|null, scored_trades: int, weak_count: int}
     */
    public function summaryForUser(int $userId): array
    {
        $trades = Position::query()
            ->closed()
            ->nonLegacy()
            ->forUser($userId)
            ->whereNotNull('protocol_score')
            ->get();

        if ($trades->isEmpty()) {
            return [
                'avg_score' => null,
                'scored_trades' => 0,
                'weak_count' => 0,
            ];
        }

        return [
            'avg_score' => round((float) $trades->avg('protocol_score'), 1),
            'scored_trades' => $trades->count(),
            'weak_count' => $trades->where('protocol_score', '<', 50)->count(),
        ];
    }

    private function stopAtOrBeyondBreakeven(Position $position): bool
    {
        if ($position->entry_price === null || $position->current_sl === null) {
            return false;
        }

        $entry = (float) $position->entry_price;
        $sl = (float) $position->current_sl;

        return $position->isShort()
            ? $sl <= $entry
            : $sl >= $entry;
    }
}
