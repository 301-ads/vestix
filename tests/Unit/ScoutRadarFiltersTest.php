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

    public function test_matches_ready_when_within_one_percent_of_entry(): void
    {
        $scout = Position::factory()->scout()->create([
            'entry_price' => 100.00,
            'latest_close_price' => 100.50,
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($scout, 'ready'));
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

        $pending = Position::factory()->scout()->pendingBrokerOrder()->create();

        $this->assertTrue(ScoutRadarFilters::matches($scout, 'scout_only'));
        $this->assertFalse(ScoutRadarFilters::matches($scout, 'pending_only'));
        $this->assertTrue(ScoutRadarFilters::matches($pending, 'pending_only'));
    }

    public function test_risk_color_thresholds(): void
    {
        $this->assertSame('success', ScoutRadarFilters::riskColor(3.5));
        $this->assertSame('warning', ScoutRadarFilters::riskColor(5.0));
        $this->assertSame('danger', ScoutRadarFilters::riskColor(8.0));
        $this->assertSame('gray', ScoutRadarFilters::riskColor(null));
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

    public function test_matches_execution_pipeline_for_pending_or_reminder(): void
    {
        $pending = Position::factory()->scout()->pendingBrokerOrder()->create();

        $reminder = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $plain = Position::factory()->scout()->create();

        $this->assertTrue(ScoutRadarFilters::matches($pending, 'execution_pipeline'));
        $this->assertTrue(ScoutRadarFilters::matches($reminder, 'execution_pipeline'));
        $this->assertFalse(ScoutRadarFilters::matches($plain, 'execution_pipeline'));
    }
}
