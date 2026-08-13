<?php

namespace Tests\Unit;

use App\Enums\ScoutReviewStatus;
use App\Enums\ScoutSource;
use App\Models\Position;
use App\Support\ScoutRadarFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SniperVisualReviewFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_pending_sniper_scan_scouts(): void
    {
        $pending = Position::factory()->scout()->create([
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::PendingVisualReview,
        ]);

        $manual = Position::factory()->scout()->create([
            'source' => null,
            'review_status' => null,
        ]);

        $approved = Position::factory()->scout()->create([
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::ActiveScout,
        ]);

        $this->assertTrue(ScoutRadarFilters::matches($pending, 'visual_review'));
        $this->assertFalse(ScoutRadarFilters::matches($manual, 'visual_review'));
        $this->assertFalse(ScoutRadarFilters::matches($approved, 'visual_review'));
        $this->assertArrayHasKey('visual_review', ScoutRadarFilters::options());
    }

    public function test_promote_sets_active_scout_review_status(): void
    {
        $scout = Position::factory()->scout()->create([
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::PendingVisualReview,
        ]);

        $scout->promoteToA();

        $this->assertSame(ScoutReviewStatus::ActiveScout, $scout->fresh()->review_status);
    }

    public function test_reject_visual_review_deletes_scout(): void
    {
        $scout = Position::factory()->scout()->create([
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::PendingVisualReview,
        ]);

        $id = $scout->id;
        $scout->rejectVisualReview();

        $this->assertDatabaseMissing('positions', ['id' => $id]);
    }

    public function test_pending_visual_review_blocks_order_plan_and_buy_stop(): void
    {
        $pending = Position::factory()->scout()->create([
            'source' => ScoutSource::SniperScan,
            'review_status' => ScoutReviewStatus::PendingVisualReview,
            'entry_price' => 100.00,
            'quantity' => 10,
            'latest_atr_14' => 2.00,
            'latest_sma_20' => 99.00,
            'latest_close_price' => 100.00,
        ]);

        $this->assertTrue($pending->isPendingVisualReview());
        $this->assertFalse($pending->canEnterOrderPlan());
        $this->assertFalse($pending->canMarkBuyStopPlaced());

        $pending->approveVisualReview();
        $pending->refresh();

        $this->assertSame(ScoutReviewStatus::ActiveScout, $pending->review_status);
        $this->assertFalse($pending->isPendingVisualReview());
        $this->assertTrue($pending->canEnterOrderPlan());
    }

    public function test_manual_scout_without_review_status_can_enter_order_plan(): void
    {
        $manual = Position::factory()->scout()->create([
            'source' => null,
            'review_status' => null,
            'entry_price' => 50.00,
        ]);

        $this->assertFalse($manual->isPendingVisualReview());
        $this->assertTrue($manual->canEnterOrderPlan());
    }
}
