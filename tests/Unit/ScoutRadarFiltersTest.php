<?php

namespace Tests\Unit;

use App\Enums\BrokerOrderStatus;
use App\Enums\PremarketScanResult;
use App\Models\Position;
use App\Support\ScoutRadarFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScoutRadarFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_ready_when_within_one_percent_of_entry_and_tradeable_grade(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.50,
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($scout, 'ready'));
    }

    public function test_does_not_match_ready_when_near_entry_but_grade_is_not_tradeable(): void
    {
        $cSetup = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
            'signal_low' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'bounce_volume_above_average' => false,
        ]);

        $hardFail = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
            'signal_low' => 99.90,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
        ]);

        $this->assertFalse(ScoutRadarFilters::matches($cSetup, 'ready'));
        $this->assertFalse(ScoutRadarFilters::matches($hardFail, 'ready'));
    }

    public function test_does_not_match_ready_when_far_from_entry(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 90.00,
        ]);

        $this->assertFalse(ScoutRadarFilters::matches($scout, 'ready'));
    }

    public function test_matches_premarket_gap_up_when_checked_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'America/New_York'));

        $scout = Position::factory()->scout()->create([
            'premarket_scan_type' => PremarketScanResult::GapRisk,
            'premarket_checked_at' => now(),
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($scout, 'gap_up'));
    }

    public function test_broker_status_filters(): void
    {
        $scout = Position::factory()->scout()->create([
            'broker_order_status' => BrokerOrderStatus::Scout,
        ]);

        $active = Position::factory()->scout()->pendingBrokerOrder()->create();

        $reminder = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($scout, 'scout_only'));
        $this->assertFalse(ScoutRadarFilters::matches($scout, 'active_only'));
        $this->assertTrue(ScoutRadarFilters::matches($active, 'active_only'));
        $this->assertTrue(ScoutRadarFilters::matches($reminder, 'market_open_pending'));
    }

    public function test_risk_color_thresholds(): void
    {
        $this->assertSame('success', ScoutRadarFilters::riskColor(3.5));
        $this->assertSame('warning', ScoutRadarFilters::riskColor(5.0));
        $this->assertSame('danger', ScoutRadarFilters::riskColor(8.0));
        $this->assertSame('gray', ScoutRadarFilters::riskColor(null));
    }

    public function test_entry_distance_percent_and_label(): void
    {
        $near = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
        ]);

        $far = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 90.00,
        ]);

        $missing = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => null,
        ]);

        $this->assertEqualsWithDelta(0.5, ScoutRadarFilters::entryDistancePercent($near), 0.001);
        $this->assertSame('−0.50%', ScoutRadarFilters::entryDistanceLabel($near));
        $this->assertSame('success', ScoutRadarFilters::entryDistanceColor($near));

        $this->assertEqualsWithDelta(10.0, ScoutRadarFilters::entryDistancePercent($far), 0.001);
        $this->assertSame('−10.00%', ScoutRadarFilters::entryDistanceLabel($far));
        $this->assertSame('gray', ScoutRadarFilters::entryDistanceColor($far));

        $this->assertNull(ScoutRadarFilters::entryDistancePercent($missing));
        $this->assertNull(ScoutRadarFilters::entryDistanceLabel($missing));
        $this->assertSame('gray', ScoutRadarFilters::entryDistanceColor($missing));
    }

    public function test_entry_price_with_distance_html_is_one_line(): void
    {
        $near = Position::factory()->scout()->create([
            'entry_price' => 23.20,
            'latest_close_price' => 23.11,
        ]);

        $html = (string) ScoutRadarFilters::entryPriceWithDistanceHtml($near);

        $this->assertStringContainsString('$23.20', $html);
        $this->assertStringContainsString('−0.39%', $html);
        $this->assertStringContainsString('vestix-entry-with-distance', $html);
        $this->assertStringContainsString('text-success-600', $html);

        $withoutClose = Position::factory()->scout()->create([
            'entry_price' => 23.20,
            'latest_close_price' => null,
        ]);

        $this->assertSame('$23.20', (string) ScoutRadarFilters::entryPriceWithDistanceHtml($withoutClose));
        $this->assertNull(ScoutRadarFilters::entryPriceWithDistanceHtml(
            Position::factory()->scout()->create(['entry_price' => null]),
        ));
    }

    public function test_track_labels_from_premarket_scan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'America/New_York'));

        $landing = Position::factory()->scout()->create([
            'premarket_scan_type' => PremarketScanResult::Landing,
            'premarket_checked_at' => now(),
        ]);

        $reclamation = Position::factory()->scout()->create([
            'premarket_scan_type' => PremarketScanResult::Reclamation,
            'premarket_checked_at' => now(),
        ]);

        $this->assertSame('A', ScoutRadarFilters::trackLabel($landing));
        $this->assertSame('B', ScoutRadarFilters::trackLabel($reclamation));
    }

    public function test_matches_premarket_signals_when_any_premarket_scan_applies(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:00:00', 'America/New_York'));

        $gap = Position::factory()->scout()->create([
            'premarket_scan_type' => PremarketScanResult::GapRisk,
            'premarket_checked_at' => now(),
        ]);

        $plain = Position::factory()->scout()->create();

        $this->assertTrue(ScoutRadarFilters::matches($gap, 'premarket_signals'));
        $this->assertFalse(ScoutRadarFilters::matches($plain, 'premarket_signals'));
    }

    public function test_matches_execution_pipeline_for_active_or_market_open_pending(): void
    {
        $active = Position::factory()->scout()->pendingBrokerOrder()->create();

        $reminder = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $plain = Position::factory()->scout()->create();

        $this->assertTrue(ScoutRadarFilters::matches($active, 'execution_pipeline'));
        $this->assertTrue(ScoutRadarFilters::matches($reminder, 'execution_pipeline'));
        $this->assertFalse(ScoutRadarFilters::matches($plain, 'execution_pipeline'));
    }

    public function test_matches_strong_setups_for_a_plus_and_a_minus(): void
    {
        $aPlus = Position::factory()->scout()->create([
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a_plus' => true,
        ]);

        $aGrade = Position::factory()->scout()->create([
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a' => true,
        ]);

        $bSetup = Position::factory()->scout()->create([
            'signal_low' => 100.50,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'scout_rsi' => 50,
            'bounce_volume_above_average' => false,
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($aPlus, 'strong_setups'));
        $this->assertTrue(ScoutRadarFilters::matches($aGrade, 'strong_setups'));
        $this->assertFalse(ScoutRadarFilters::matches($bSetup, 'strong_setups'));
    }

    public function test_matches_a_plus_for_grade_a_plus_plus_only(): void
    {
        $aPlusPlus = Position::factory()->scout()->create([
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
            'trader_promoted_a_plus' => true,
        ]);

        $aGrade = Position::factory()->scout()->create([
            'signal_low' => 100.50,
            'latest_open_price' => 100.00,
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => false,
            'pre_bounce_extension_atr' => 2.50,
        ]);

        $perfectUnpromoted = Position::factory()->scout()->create([
            'signal_low' => 101.00,
            'latest_open_price' => 100.00,
            'latest_close_price' => 101.00,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.50,
            'sma_20_ten_days_ago' => 98.00,
            'latest_sma_50' => 98.00,
            'scout_rsi' => 50.00,
            'bounce_volume_above_average' => true,
            'relative_volume' => 1.40,
            'sector_etf' => 'XLK',
            'sector_trend_positive' => true,
            'pre_bounce_extension_atr' => 2.50,
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($aPlusPlus, 'a_plus'));
        $this->assertFalse(ScoutRadarFilters::matches($aGrade, 'a_plus'));
        $this->assertFalse(ScoutRadarFilters::matches($perfectUnpromoted, 'a_plus'));
    }
}
