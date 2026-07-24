<?php

namespace Tests\Unit\Kluis;

use App\Enums\KluisClimate;
use App\Models\User;
use App\Models\VaultDeposit;
use App\Services\Kluis\KluisMarketDataService;
use App\Services\Kluis\VaultService;
use App\Support\Kluis\KluisThermometerReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class VaultServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_month_updates_dry_powder_and_writes_deposit(): void
    {
        $user = User::factory()->create();
        $vault = app(VaultService::class);
        $settings = $vault->settingsFor($user);
        $settings->update(['dry_powder_balance' => 0]);

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Overheat,
            deviationPct: 12.5,
            close: 112.5,
            sma200: 100,
            ticker: 'VWCE',
        );

        $deposit = $vault->confirmMonth($user, 10000, $reading);

        $this->assertSame(5000.0, (float) $deposit->etf_amount);
        $this->assertSame(5000.0, (float) $deposit->dry_powder_delta);
        $this->assertSame(5000.0, (float) $settings->fresh()->dry_powder_balance);
        $this->assertSame(1, VaultDeposit::query()->where('user_id', $user->id)->count());
    }

    public function test_confirm_month_rejects_duplicate_period(): void
    {
        $user = User::factory()->create();
        $vault = app(VaultService::class);
        $vault->settingsFor($user);

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 2,
            close: 102,
            sma200: 100,
            ticker: 'VWCE',
        );

        $vault->confirmMonth($user, 10000, $reading);

        $this->expectException(ValidationException::class);
        $vault->confirmMonth($user, 10000, $reading);
    }

    public function test_revert_deposit_restores_dry_powder_and_allows_reconfirm(): void
    {
        $user = User::factory()->create();
        $vault = app(VaultService::class);
        $settings = $vault->settingsFor($user);
        $settings->update(['dry_powder_balance' => 0]);

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Overheat,
            deviationPct: 12,
            close: 112,
            sma200: 100,
            ticker: 'VWCE',
        );

        $deposit = $vault->confirmMonth($user, 10000, $reading);
        $this->assertSame(5000.0, (float) $settings->fresh()->dry_powder_balance);

        $vault->revertDeposit($user, $deposit);

        $this->assertSame(0.0, (float) $settings->fresh()->dry_powder_balance);
        $this->assertSame(0, VaultDeposit::query()->where('user_id', $user->id)->count());

        $vault->confirmMonth($user, 10000, $reading);
        $this->assertSame(1, VaultDeposit::query()->where('user_id', $user->id)->count());
    }

    public function test_reading_delegates_to_market_data_service(): void
    {
        $user = User::factory()->create();
        $settings = app(VaultService::class)->settingsFor($user);

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 1,
            close: 101,
            sma200: 100,
            ticker: 'VWCE',
        );

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')->once()->with(Mockery::type(\App\Models\VaultSetting::class), false)->andReturn($reading);
        $this->app->instance(KluisMarketDataService::class, $market);

        $result = app(VaultService::class)->reading($settings);

        $this->assertSame($reading, $result);
    }
}
