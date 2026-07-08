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
}
