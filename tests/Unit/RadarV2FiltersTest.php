<?php

namespace Tests\Unit;

use App\Support\CandleAnatomy;
use App\Support\FreeAirspaceScanner;
use App\Support\ScoutSetupScorecard;
use App\Support\SniperSetupFilter;
use Tests\TestCase;

class RadarV2FiltersTest extends TestCase
{
    public function test_free_airspace_blocks_sma50_between_entry_and_target1_long(): void
    {
        $reason = FreeAirspaceScanner::blockadeReason([
            'direction' => 'long',
            'entry_price' => 100.0,
            'stop_price' => 95.0,
            'target_1_rr' => 2.0,
            'latest_sma_50' => 105.0,
            'latest_sma_200' => 80.0,
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('SMA-50', $reason);
    }

    public function test_free_airspace_allows_clear_path(): void
    {
        $reason = FreeAirspaceScanner::blockadeReason([
            'direction' => 'long',
            'entry_price' => 100.0,
            'stop_price' => 95.0,
            'target_1_rr' => 2.0,
            'latest_sma_50' => 90.0,
            'latest_sma_200' => 80.0,
        ]);

        $this->assertNull($reason);
    }

    public function test_candle_anatomy_rejects_weak_close(): void
    {
        $reason = CandleAnatomy::failReason([
            'direction' => 'long',
            'signal_high' => 110.0,
            'signal_low' => 100.0,
            'latest_close_price' => 102.0, // 20% of range
        ]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Röntgenfoto', $reason);
    }

    public function test_candle_anatomy_accepts_strong_close(): void
    {
        $reason = CandleAnatomy::failReason([
            'direction' => 'long',
            'signal_high' => 110.0,
            'signal_low' => 100.0,
            'latest_close_price' => 108.0, // 80% of range
        ]);

        $this->assertNull($reason);
    }

    public function test_scorecard_hard_fails_on_blocked_airspace(): void
    {
        $result = ScoutSetupScorecard::evaluate([
            'signal_low' => 95.0,
            'signal_high' => 101.0,
            'latest_open_price' => 100.0,
            'latest_close_price' => 100.5,
            'latest_sma_20' => 100.0,
            'sma_20_five_days_ago' => 99.5,
            'sma_20_ten_days_ago' => 98.0,
            'latest_sma_50' => 105.0,
            'latest_sma_200' => 80.0,
            'latest_atr_14' => 2.0,
            'entry_price' => 100.0,
            'stop_price' => 95.0,
            'target_1_rr' => 2.0,
            'scout_rsi' => 50.0,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'bounce_day_volume' => 14_000_000,
            'volume_sma_20' => 10_000_000,
            'sector_etf' => 'XLF',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ]);

        $this->assertSame('NO TRADE', $result['grade']);
        $this->assertTrue(
            collect($result['hardFailReasons'])->contains(fn (string $r): bool => str_contains($r, 'Blokkade')),
        );
    }

    public function test_min_price_blocker(): void
    {
        $this->assertFalse(SniperSetupFilter::passesMinPrice(9.99));
        $this->assertTrue(SniperSetupFilter::passesMinPrice(10.0));
    }

    public function test_filter_rejects_weak_candle_anatomy_when_ohlc_present(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.0,
            'high' => 110.0,
            'low' => 100.0,
            'close' => 101.0, // weak close in range
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 45.0,
        ]));

        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 100.0,
            'high' => 101.2,
            'low' => 99.5,
            'close' => 101.0, // strong close near high
            'sma10' => 101.5,
            'sma20' => 100.0,
            'sma50' => 98.0,
            'rsi14' => 45.0,
        ]));
    }

    public function test_filter_accepts_red_hammer_with_strong_close(): void
    {
        // Red body, but close in upper quartile of the range (Röntgenfoto).
        $this->assertSame('long', SniperSetupFilter::evaluate([
            'open' => 100.95,
            'high' => 101.20,
            'low' => 99.50,
            'close' => 100.90, // ~82% of range, still under open
            'sma10' => 101.50,
            'sma20' => 100.00,
            'sma50' => 98.00,
            'rsi14' => 45.0,
        ]));
    }

    public function test_filter_rejects_weak_red_close(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 101.00,
            'high' => 101.20,
            'low' => 99.50,
            'close' => 100.10, // red and only ~35% of range
            'sma10' => 101.50,
            'sma20' => 100.00,
            'sma50' => 98.00,
            'rsi14' => 45.0,
        ]));
    }

    public function test_filter_rejects_red_candle_without_ohlc_range(): void
    {
        $this->assertNull(SniperSetupFilter::evaluate([
            'open' => 100.95,
            'close' => 100.90,
            'sma10' => 101.50,
            'sma20' => 100.00,
            'sma50' => 98.00,
            'rsi14' => 45.0,
        ]));
    }
}
