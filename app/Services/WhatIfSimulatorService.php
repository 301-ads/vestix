<?php

namespace App\Services;

use App\Enums\TradeDirection;
use App\Models\Position;
use App\Models\SniperDailyBar;

class WhatIfSimulatorService
{
    /**
     * Recalculate outcome if stop/exit had been different, walking daily bars after entry.
     *
     * @return array{
     *     original_pnl: float,
     *     simulated_pnl: float,
     *     delta_pnl: float,
     *     original_r: ?float,
     *     simulated_r: ?float,
     *     exit_reason: string,
     *     simulated_exit_price: float,
     * }
     */
    public function simulate(
        Position $position,
        ?float $alternateStop = null,
        ?float $alternateExit = null,
    ): array {
        $entry = (float) $position->entry_price;
        $qty = (float) ($position->quantity ?? 0);
        $scaled = (float) ($position->scaled_out_quantity ?? 0);
        $runnerQty = max(0.0, $qty - $scaled);
        $short = $position->tradeDirection() === TradeDirection::Short;

        $originalPnl = (float) $position->unrealized_pnl;
        $originalR = $position->rMultiple();

        $stop = $alternateStop ?? (
            $position->initial_sl !== null
                ? (float) $position->initial_sl
                : ($position->current_sl !== null ? (float) $position->current_sl : null)
        );
        $plannedExit = $alternateExit ?? (
            $position->exit_price !== null ? (float) $position->exit_price : null
        );

        if ($entry <= 0 || $runnerQty <= 0 || $stop === null || $plannedExit === null) {
            return [
                'original_pnl' => $originalPnl,
                'simulated_pnl' => $originalPnl,
                'delta_pnl' => 0.0,
                'original_r' => $originalR,
                'simulated_r' => $originalR,
                'exit_reason' => 'Onvoldoende data voor simulatie',
                'simulated_exit_price' => $plannedExit ?? $entry,
            ];
        }

        $riskPerShare = abs($entry - (float) (
            $position->initial_sl ?? $position->current_sl ?? $stop
        ));

        $entryDate = optional($position->signal_bar_date ?? $position->detected_signal_bar_date)?->toDateString()
            ?? optional($position->created_at)?->toDateString();
        $endDate = optional($position->closed_at)?->toDateString();

        $bars = SniperDailyBar::query()
            ->where('ticker', strtoupper($position->ticker))
            ->when($entryDate, fn ($q) => $q->where('date', '>=', $entryDate))
            ->when($endDate, fn ($q) => $q->where('date', '<=', $endDate))
            ->orderBy('date')
            ->get();

        $simulatedExit = $plannedExit;
        $exitReason = 'Geplande exit';

        foreach ($bars as $bar) {
            $low = (float) $bar->low;
            $high = (float) $bar->high;

            if ($short) {
                if ($high >= $stop) {
                    $simulatedExit = $stop;
                    $exitReason = 'Alternatieve stop geraakt';
                    break;
                }
            } elseif ($low <= $stop) {
                $simulatedExit = $stop;
                $exitReason = 'Alternatieve stop geraakt';
                break;
            }
        }

        $legPnl = $short
            ? ($entry - $simulatedExit) * $runnerQty
            : ($simulatedExit - $entry) * $runnerQty;
        $realized = (float) ($position->realized_pnl ?? 0);
        $simulatedPnl = $realized + $legPnl;

        $simulatedR = $riskPerShare > 0
            ? round((($short ? ($entry - $simulatedExit) : ($simulatedExit - $entry)) / $riskPerShare), 2)
            : null;

        return [
            'original_pnl' => round($originalPnl, 2),
            'simulated_pnl' => round($simulatedPnl, 2),
            'delta_pnl' => round($simulatedPnl - $originalPnl, 2),
            'original_r' => $originalR,
            'simulated_r' => $simulatedR,
            'exit_reason' => $exitReason,
            'simulated_exit_price' => round($simulatedExit, 2),
        ];
    }
}
