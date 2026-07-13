<?php

namespace Tests\Unit;

use App\Enums\BrokerOrderStatus;
use App\Enums\ScoutPipelineStatus;
use App\Models\Position;
use App\Services\StaleBuyStopReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StaleBuyStopReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flags_active_scout_and_resets_broker_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 23:00:00', 'Europe/Amsterdam'));

        $scout = Position::factory()->scout()->pendingBrokerOrder()->create([
            'ticker' => 'APTV',
            'latest_close_price' => 100.50,
            'latest_sma_20' => 100.00,
            'sma_20_five_days_ago' => 99.00,
            'latest_sma_50' => 95.00,
            'scout_rsi' => 50.00,
        ]);

        $flagged = app(StaleBuyStopReviewService::class)->flagStaleBuyStops();

        $scout->refresh();

        $this->assertSame(1, $flagged);
        $this->assertSame(BrokerOrderStatus::Scout, $scout->broker_order_status);
        $this->assertSame('2026-07-13', $scout->buy_stop_review_required_on->toDateString());
        $this->assertNotNull($scout->buy_stop_review_setup_score);
        $this->assertNotNull($scout->buy_stop_review_setup_grade);
        $this->assertSame(ScoutPipelineStatus::ReviewRequired, $scout->scoutPipelineStatus());

        Carbon::setTestNow();
    }

    public function test_does_not_flag_scout_without_live_order(): void
    {
        Position::factory()->scout()->create();

        $flagged = app(StaleBuyStopReviewService::class)->flagStaleBuyStops();

        $this->assertSame(0, $flagged);
    }

    public function test_does_not_reflag_scout_already_reviewed_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 23:00:00', 'Europe/Amsterdam'));

        Position::factory()->scout()->pendingBrokerOrder()->create([
            'buy_stop_review_required_on' => '2026-07-13',
            'buy_stop_review_setup_score' => 8,
            'buy_stop_review_setup_grade' => 'A',
        ]);

        $flagged = app(StaleBuyStopReviewService::class)->flagStaleBuyStops();

        $this->assertSame(0, $flagged);

        Carbon::setTestNow();
    }
}
