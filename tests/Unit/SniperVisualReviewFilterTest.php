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
}
