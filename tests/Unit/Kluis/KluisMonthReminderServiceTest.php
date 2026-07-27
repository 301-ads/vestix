<?php

namespace Tests\Unit\Kluis;

use App\Enums\KluisClimate;
use App\Models\User;
use App\Services\Kluis\KluisMarketDataService;
use App\Services\Kluis\KluisMonthReminderService;
use App\Services\Kluis\VaultService;
use App\Support\Kluis\KluisThermometerReading;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class KluisMonthReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_before_tenth_of_month(): void
    {
        $user = User::factory()->create();
        app(VaultService::class)->settingsFor($user);

        $summary = app(KluisMonthReminderService::class)->run(Carbon::parse('2026-07-09 10:00', 'Europe/Amsterdam'));

        $this->assertSame(0, $summary['sent']);
        $this->assertSame(0, $summary['skipped']);
    }

    public function test_sends_when_month_not_confirmed(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        app(VaultService::class)->settingsFor($user);

        $summary = app(KluisMonthReminderService::class)->run(Carbon::parse('2026-07-10 10:00', 'Europe/Amsterdam'));

        $this->assertSame(1, $summary['sent']);
        Notification::assertSentTo($user, DatabaseNotification::class);
    }

    public function test_skips_when_already_confirmed(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 1,
            close: 100,
            sma200: 99,
            ticker: 'VWCE',
        );

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')->andReturn($reading);
        $market->shouldReceive('fetchHoldingsPrice')->andReturn(null);
        $this->app->instance(KluisMarketDataService::class, $market);

        $vault = app(VaultService::class);
        $vault->settingsFor($user);
        $vault->confirmMonth(
            $user,
            1000,
            $reading,
            Carbon::parse('2026-07-01'),
        );

        $summary = app(KluisMonthReminderService::class)->run(Carbon::parse('2026-07-10 10:00', 'Europe/Amsterdam'));

        $this->assertSame(0, $summary['sent']);
        $this->assertSame(1, $summary['skipped']);
    }
}
