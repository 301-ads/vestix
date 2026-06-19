<?php

namespace App\Support;

use App\Models\Position;

class ShareCardDataFactory
{
    /**
     * @return array{
     *     ticker: string,
     *     ticker_initial: string,
     *     roi_percentage: float,
     *     roi_formatted: string,
     *     status_label: string,
     *     status_variant: string,
     *     entry_price: string,
     *     current_price: string,
     *     holding_days: int,
     *     subtitle: string,
     * }
     */
    public static function fromPosition(Position $position): array
    {
        $roi = $position->unrealized_pnl_percentage;
        $isFreeride = $position->isFreerideSecured();
        $isClosed = $position->status === 'closed';

        $currentPrice = $isClosed
            ? (float) $position->exit_price
            : (float) ($position->latest_close_price ?? $position->entry_price ?? 0);

        $statusLabel = $isFreeride
            ? 'Freeride Secured'
            : ($isClosed ? 'Trade Closed' : 'Open Winner');

        $statusVariant = $isFreeride ? 'freeride' : ($isClosed ? 'closed' : 'open');

        $subtitle = $isFreeride
            ? 'Ongerealiseerde P&L | Volledig afgedekt door wiskundige SL'
            : ($isClosed ? 'Gerealiseerde return' : 'Actuele performance');

        return [
            'ticker' => $position->ticker,
            'ticker_initial' => strtoupper(substr($position->ticker, 0, 1)),
            'roi_percentage' => $roi,
            'roi_formatted' => ($roi >= 0 ? '+' : '').number_format($roi, 2).'%',
            'status_label' => $statusLabel,
            'status_variant' => $statusVariant,
            'entry_price' => '$'.number_format((float) $position->entry_price, 2),
            'current_price' => '$'.number_format($currentPrice, 2),
            'holding_days' => $position->holdingDays(),
            'subtitle' => $subtitle,
        ];
    }
}
