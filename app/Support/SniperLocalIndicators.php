<?php

namespace App\Support;

use App\Models\SniperDailyBar;
use Illuminate\Support\Collection;

class SniperLocalIndicators
{
    /**
     * @return array{
     *     open: float,
     *     high: float,
     *     low: float,
     *     close: float,
     *     volume: int,
     *     date: string,
     *     previousClose: float|null,
     *     sma10: float,
     *     sma20: float,
     *     sma20FiveDaysAgo: float|null,
     *     sma50: float,
     *     sma200: float|null,
     *     rsi14: float,
     * }|null
     */
    public function forTicker(string $ticker): ?array
    {
        $minBars = (int) config('vestix.sniper_scanner.min_bars_for_ready', 50);

        /** @var Collection<int, SniperDailyBar> $bars */
        $bars = SniperDailyBar::query()
            ->where('ticker', strtoupper(trim($ticker)))
            ->orderBy('date')
            ->get();

        if ($bars->count() < $minBars) {
            return null;
        }

        $closes = $bars->map(fn (SniperDailyBar $bar): float => (float) $bar->close)->values()->all();
        $latest = $bars->last();
        $previous = $bars->count() >= 2 ? $bars->get($bars->count() - 2) : null;

        $sma10 = TechnicalIndicators::sma($closes, 10);
        $sma20 = TechnicalIndicators::sma($closes, 20);
        $sma20FiveDaysAgo = TechnicalIndicators::smaAtOffset(
            $closes,
            20,
            ScoutSetupScorecard::smaSlopeHardFailLookbackDays(),
        );
        $sma50 = TechnicalIndicators::sma($closes, 50);
        $sma200 = TechnicalIndicators::sma($closes, 200);
        $rsi14 = TechnicalIndicators::wilderRsi($closes, 14);

        if ($sma10 === null || $sma20 === null || $sma50 === null || $rsi14 === null || $latest === null) {
            return null;
        }

        return [
            'open' => (float) $latest->open,
            'high' => (float) $latest->high,
            'low' => (float) $latest->low,
            'close' => (float) $latest->close,
            'volume' => (int) $latest->volume,
            'date' => $latest->date->toDateString(),
            'previousClose' => $previous !== null ? (float) $previous->close : null,
            'sma10' => $sma10,
            'sma20' => $sma20,
            'sma20FiveDaysAgo' => $sma20FiveDaysAgo,
            'sma50' => $sma50,
            'sma200' => $sma200,
            'rsi14' => $rsi14,
        ];
    }
}
