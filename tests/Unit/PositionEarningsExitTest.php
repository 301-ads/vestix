<?php

namespace Tests\Unit;

use App\Enums\EarningsExitUrgency;
use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PositionEarningsExitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_requiring_action_includes_earnings_exit_positions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-06', 'Europe/Amsterdam'));

        $user = User::factory()->create();
        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'META',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Amc,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'META',
            'asset_id' => $asset->id,
            'status' => 'open',
            'latest_close_price' => 100,
            'current_sl' => 95,
            'latest_sma_20' => 98,
            'latest_atr_14' => 4,
        ]);

        $actions = Position::requiringActionForUser($user->id);

        $this->assertCount(1, $actions);
        $this->assertTrue($actions->first()->requiresEarningsExit());
        $this->assertSame(EarningsExitUrgency::Prepare, $actions->first()->earningsExitUrgency());
    }

    public function test_held_through_earnings_suppresses_exit_urgency_for_current_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14', 'Europe/Amsterdam'));

        $user = User::factory()->create();
        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
            'latest_close_price' => 59.86,
            'current_sl' => 58.14,
            'latest_sma_20' => 57.00,
            'latest_atr_14' => 1.50,
        ]);

        $this->assertSame(EarningsExitUrgency::Overdue, $position->earningsExitUrgency());
        $this->assertTrue($position->requiresEarningsExit());

        $position->acknowledgeHeldThroughEarnings();
        $position->refresh();

        $this->assertTrue($position->heldThroughEarningsForCurrentCycle());
        $this->assertNull($position->earningsExitUrgency());
        $this->assertFalse($position->requiresEarningsExit());
        $this->assertNull($position->primaryActionType());
        $this->assertCount(0, Position::requiringActionForUser($user->id));
    }

    public function test_held_through_earnings_resumes_when_next_earnings_cycle_arrives(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14', 'Europe/Amsterdam'));

        $user = User::factory()->create();
        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        $position = Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
            'held_through_earnings_date' => '2026-07-14',
            'held_through_earnings_at' => now(),
        ]);

        $this->assertFalse($position->requiresEarningsExit());

        $asset->update(['next_earnings_date' => '2026-10-14']);
        $position->load('asset');

        Carbon::setTestNow(Carbon::parse('2026-10-12', 'Europe/Amsterdam'));

        $this->assertFalse($position->heldThroughEarningsForCurrentCycle());
        $this->assertTrue($position->requiresEarningsExit());
        $this->assertSame(EarningsExitUrgency::Prepare, $position->earningsExitUrgency());
    }
}
