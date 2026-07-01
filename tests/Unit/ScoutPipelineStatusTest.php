<?php

namespace Tests\Unit;

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
    }

    public function test_pending_when_market_open_reminder_scheduled(): void
    {
        $scout = Position::factory()->scout()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $this->assertSame(ScoutPipelineStatus::Pending, $scout->scoutPipelineStatus());
    }

    public function test_active_when_order_live_at_broker(): void
    {
        $scout = Position::factory()->scout()->pendingBrokerOrder()->create();

        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }

    public function test_active_takes_priority_over_reminder(): void
    {
        $scout = Position::factory()->scout()->pendingBrokerOrder()->create([
            'market_open_reminder_on' => '2026-07-03',
        ]);

        $this->assertSame(ScoutPipelineStatus::Active, $scout->scoutPipelineStatus());
    }
}
