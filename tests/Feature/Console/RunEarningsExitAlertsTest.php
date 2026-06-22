<?php

namespace Tests\Feature\Console;

use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunEarningsExitAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_runs_warning_phase_for_monday_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'TSLA',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'TSLA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $this->artisan('vestix:earnings-exit-alerts --phase=warning')
            ->assertSuccessful();
    }
}
