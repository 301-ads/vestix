<?php

namespace Tests\Unit;

use App\Enums\AlertEventType;
use App\Enums\EarningsReleaseHour;
use App\Models\Asset;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\EarningsExitAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EarningsExitAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_warning_on_warning_day_for_monday_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'NVDA',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('warning');

        $this->assertSame(1, $summary['warning']);
        $this->assertSame(0, $summary['action']);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsWarning)->count());
    }

    public function test_sends_action_on_friday_for_monday_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-06 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'NVDA',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('action');

        $this->assertSame(0, $summary['warning']);
        $this->assertSame(1, $summary['action']);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsActionRequired)->count());
    }

    public function test_does_not_send_duplicate_earnings_alerts_for_same_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'NVDA',
            'next_earnings_date' => '2026-03-09',
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $service = app(EarningsExitAlertService::class);
        $service->run('warning');
        $service->run('warning');

        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsWarning)->count());
    }

    public function test_sends_action_on_earnings_day_for_amc_monday_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-09 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'NVDA',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Amc,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('action');

        $this->assertSame(0, $summary['warning']);
        $this->assertSame(1, $summary['action']);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsActionRequired)->count());
    }

    public function test_sends_weekend_reminder_before_bmo_exit_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 09:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('weekend');

        $this->assertSame(0, $summary['warning']);
        $this->assertSame(0, $summary['action']);
        $this->assertSame(1, $summary['weekend']);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsActionRequired)->count());
    }

    public function test_sends_action_on_monday_for_bmo_tuesday_earnings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('action');

        $this->assertSame(0, $summary['warning']);
        $this->assertSame(1, $summary['action']);
        $this->assertEquals(1, PositionAlert::query()->where('event_type', AlertEventType::EarningsActionRequired)->count());
    }

    public function test_sends_final_reminder_at_2130_for_bmo_action_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 21:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('final');

        $this->assertSame(1, $summary['final']);
        $this->assertEquals(1, PositionAlert::query()
            ->where('event_type', AlertEventType::EarningsFinalReminder)
            ->count());
    }

    public function test_does_not_send_final_reminder_for_amc_on_action_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-09 21:30:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'NVDA',
            'next_earnings_date' => '2026-03-09',
            'next_earnings_hour' => EarningsReleaseHour::Amc,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'NVDA',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $summary = app(EarningsExitAlertService::class)->run('final');

        $this->assertSame(0, $summary['final']);
    }

    public function test_sends_morning_and_final_alerts_on_bmo_action_day(): void
    {
        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
        ]);

        $service = app(EarningsExitAlertService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-13 08:00:00', 'Europe/Amsterdam'));
        $service->run('action');

        Carbon::setTestNow(Carbon::parse('2026-07-13 21:30:00', 'Europe/Amsterdam'));
        $service->run('final');

        $this->assertEquals(2, PositionAlert::query()->whereIn('event_type', [
            AlertEventType::EarningsActionRequired,
            AlertEventType::EarningsFinalReminder,
        ])->count());
    }

    public function test_skips_earnings_alerts_when_position_held_through_current_cycle(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-13 08:00:00', 'Europe/Amsterdam'));

        config(['vestix.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $user = User::factory()->create(['telegram_chat_id' => '12345']);
        UserAlertPreference::ensureDefaultsForUser($user);

        $asset = Asset::factory()->withoutIcon()->create([
            'ticker' => 'BAC',
            'next_earnings_date' => '2026-07-14',
            'next_earnings_hour' => EarningsReleaseHour::Bmo,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'asset_id' => $asset->id,
            'status' => 'open',
            'held_through_earnings_date' => '2026-07-14',
            'held_through_earnings_at' => now(),
        ]);

        $summary = app(EarningsExitAlertService::class)->run('action');

        $this->assertSame(0, $summary['action']);
        $this->assertEquals(0, PositionAlert::query()->count());
    }
}
