<?php

namespace Tests\Unit;

use App\Support\SignalCandleResolver;
use Tests\TestCase;

class SignalCandleResolverTest extends TestCase
{
    public function test_finds_latest_bounce_bar_with_per_bar_sma(): void
    {
        $bars = $this->flatBars(25, close: 100.0);

        // Older bounce candidate that should be ignored once a newer one exists.
        $bars[20]['low'] = 98.0;
        $bars[20]['close'] = 101.0;

        // Latest bar: bounce (low under SMA, close above).
        $bars[24]['low'] = 97.0;
        $bars[24]['close'] = 102.0;
        $bars[24]['high'] = 103.0;

        $bounce = SignalCandleResolver::latestBounceBar($bars);

        $this->assertNotNull($bounce);
        $this->assertSame('2024-02-25', $bounce['date']);
        $this->assertEqualsWithDelta(97.0, $bounce['low'], 0.01);
        $this->assertEqualsWithDelta(103.0, $bounce['high'], 0.01);
    }

    public function test_finds_latest_rejection_bar(): void
    {
        $bars = $this->flatBars(25, close: 100.0);
        $bars[24]['high'] = 103.0;
        $bars[24]['close'] = 98.0;
        $bars[24]['low'] = 97.5;

        $rejection = SignalCandleResolver::latestRejectionBar($bars);

        $this->assertNotNull($rejection);
        $this->assertSame('2024-02-25', $rejection['date']);
        $this->assertEqualsWithDelta(103.0, $rejection['high'], 0.01);
        $this->assertEqualsWithDelta(97.5, $rejection['low'], 0.01);
    }

    public function test_returns_null_when_no_signal_bar_matches(): void
    {
        $bars = $this->flatBars(25, close: 100.0);

        $this->assertNull(SignalCandleResolver::latestBounceBar($bars));
        $this->assertNull(SignalCandleResolver::latestRejectionBar($bars));
    }

    public function test_extremes_since_signal_include_later_session_high(): void
    {
        $bars = $this->flatBars(25, close: 100.0);
        $bars[22]['high'] = 28.04;
        $bars[22]['low'] = 27.56;
        $bars[22]['date'] = '2026-08-12';
        $bars[23]['high'] = 28.54;
        $bars[23]['low'] = 27.83;
        $bars[23]['date'] = '2026-08-13';
        $bars[24]['high'] = 28.10;
        $bars[24]['low'] = 27.90;
        $bars[24]['date'] = '2026-08-14';

        $extremes = SignalCandleResolver::extremesSince($bars, '2026-08-12');

        $this->assertNotNull($extremes);
        $this->assertEqualsWithDelta(28.54, $extremes['high'], 0.01);
        $this->assertEqualsWithDelta(27.56, $extremes['low'], 0.01);
    }

    public function test_extremes_since_signal_ignore_bars_before_signal(): void
    {
        $bars = $this->flatBars(3, close: 100.0);
        $bars[0]['date'] = '2026-08-11';
        $bars[0]['high'] = 40.00;
        $bars[0]['low'] = 10.00;
        $bars[1]['date'] = '2026-08-12';
        $bars[1]['high'] = 28.04;
        $bars[1]['low'] = 27.56;
        $bars[2]['date'] = '2026-08-13';
        $bars[2]['high'] = 28.20;
        $bars[2]['low'] = 27.80;

        $extremes = SignalCandleResolver::extremesSince($bars, '2026-08-12');

        $this->assertNotNull($extremes);
        $this->assertEqualsWithDelta(28.20, $extremes['high'], 0.01);
        $this->assertEqualsWithDelta(27.56, $extremes['low'], 0.01);
    }

    /**
     * @return list<array{open: float, high: float, low: float, close: float, volume: float, date: string}>
     */
    private function flatBars(int $count, float $close): array
    {
        $bars = [];

        for ($day = 1; $day <= $count; $day++) {
            $bars[] = [
                'open' => $close,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'volume' => 1_000_000.0,
                'date' => sprintf('2024-02-%02d', $day),
            ];
        }

        return $bars;
    }
}
