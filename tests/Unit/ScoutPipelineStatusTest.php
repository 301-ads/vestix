<?php

namespace Tests\Unit;

use App\Enums\Broker;
use App\Enums\ScoutPipelineStatus;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoutPipelineStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_scout_when_on_radar_only(): void
    {
        $scout = Position::factory()->scout()->create();

        $this->assertSame(ScoutPipelineStatus::Scout, $scout->scoutPipelineStatus());
        $this->assertSame('Pending', $scout->scoutPipelineStatus()->label());
        $this->assertSame('info', $scout->scoutPipelineStatus()->badgeColor());
    }

    public function test_pending_when_market_open_reminder_scheduled(): void
    {
        $scout = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $this->assertSame(ScoutPipelineStatus::Pending, $scout->scoutPipelineStatus());
        $this->assertSame('Reminder', $scout->scoutPipelineStatus()->label());
        $this->assertSame('gray', $scout->scoutPipelineStatus()->badgeColor());
    }

    public function test_active_when_order_live_at_broker(): void
    {
        $scout = Position::factory()->scout()->pendingBrokerOrder()->create();

        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
        $this->assertSame('Active', $scout->scoutPipelineStatus()->label());
        $this->assertSame('info', $scout->scoutPipelineStatus()->badgeColor());
    }

    public function test_active_takes_priority_over_reminder(): void
    {
        $scout = Position::factory()->scout()->pendingBrokerOrder()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }

    public function test_review_required_takes_priority_over_active(): void
    {
        $scout = Position::factory()->scout()->pendingBrokerOrder()->requiringBuyStopReview()->create();

        $this->assertSame(ScoutPipelineStatus::ReviewRequired, $scout->scoutPipelineStatus());
    }

    public function test_broker_short_labels(): void
    {
        $this->assertSame('IBKR', Broker::Ibkr->shortLabel());
        $this->assertSame('Revolut', Broker::Revolut->shortLabel());
        $this->assertSame('Handmatig', Broker::None->shortLabel());
    }
}
