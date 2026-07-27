<?php

namespace Tests\Unit\Kluis;

use App\Enums\KluisClimate;
use App\Enums\VaultTransactionSource;
use App\Models\User;
use App\Models\VaultDeposit;
use App\Models\VaultSetting;
use App\Models\VaultTransaction;
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

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Overheat,
            deviationPct: 12.5,
            close: 112.5,
            sma200: 100,
            ticker: 'VWCE',
        );

        $this->mockFreshReading($reading);

        $vault = app(VaultService::class);
        $settings = $vault->settingsFor($user);
        $settings->update(['dry_powder_balance' => 0]);

        $deposit = $vault->confirmMonth($user, 10000, $reading);

        $this->assertSame(5000.0, (float) $deposit->etf_amount);
        $this->assertSame(5000.0, (float) $deposit->dry_powder_delta);
        $this->assertSame(5000.0, (float) $settings->fresh()->dry_powder_balance);
        $this->assertSame(1, VaultDeposit::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, VaultTransaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(VaultTransactionSource::MonthlyConfirm, VaultTransaction::query()->first()->source);
    }

    public function test_confirm_month_uses_fill_details_when_provided(): void
    {
        $user = User::factory()->create();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 2,
            close: 165,
            sma200: 160,
            ticker: 'VWCE',
        );

        $this->mockFreshReading($reading);

        $vault = app(VaultService::class);
        $vault->settingsFor($user);

        $vault->confirmMonth($user, 3850, $reading, fill: [
            'shares' => 23.3381,
            'fill_price' => 164.97,
            'etf_amount' => 3850.00,
            'fee' => 2.31,
        ]);

        $tx = VaultTransaction::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame(23.3381, (float) $tx->shares);
        $this->assertSame(164.97, (float) $tx->fill_price);
        $this->assertSame(2.31, (float) $tx->fee);
        $this->assertEqualsWithDelta(3852.31, $tx->costBasis(), 0.01);
    }

    public function test_confirm_month_rejects_duplicate_period(): void
    {
        $user = User::factory()->create();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 2,
            close: 102,
            sma200: 100,
            ticker: 'VWCE',
        );

        $this->mockFreshReading($reading);

        $vault = app(VaultService::class);
        $vault->settingsFor($user);

        $vault->confirmMonth($user, 10000, $reading);

        $this->expectException(ValidationException::class);
        $vault->confirmMonth($user, 10000, $reading);
    }

    public function test_confirm_month_fails_without_reading(): void
    {
        $user = User::factory()->create();
        $vault = app(VaultService::class);
        $vault->settingsFor($user);

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')->andReturn(null);
        $market->shouldReceive('fetchHoldingsPrice')->andReturn(null);
        $this->app->instance(KluisMarketDataService::class, $market);

        $this->expectException(ValidationException::class);
        app(VaultService::class)->confirmMonth($user, 10000);
    }

    public function test_revert_deposit_restores_dry_powder_and_removes_fill(): void
    {
        $user = User::factory()->create();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Overheat,
            deviationPct: 12,
            close: 112,
            sma200: 100,
            ticker: 'VWCE',
        );

        $this->mockFreshReading($reading);

        $vault = app(VaultService::class);
        $settings = $vault->settingsFor($user);
        $settings->update(['dry_powder_balance' => 0]);

        $deposit = $vault->confirmMonth($user, 10000, $reading);
        $this->assertSame(5000.0, (float) $settings->fresh()->dry_powder_balance);
        $this->assertSame(1, VaultTransaction::query()->where('user_id', $user->id)->count());

        $vault->revertDeposit($user, $deposit);

        $this->assertSame(0.0, (float) $settings->fresh()->dry_powder_balance);
        $this->assertSame(0, VaultDeposit::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, VaultTransaction::query()->where('user_id', $user->id)->count());
    }

    public function test_historical_purchase_and_holdings_summary(): void
    {
        $user = User::factory()->create();
        $vault = app(VaultService::class);
        $vault->settingsFor($user);

        $vault->addHistoricalPurchase($user, [
            'traded_at' => '2026-05-13 11:05:53',
            'shares' => 47.1105,
            'fill_price' => 159.20,
            'etf_amount' => 7499.99,
            'fee' => 4.50,
        ]);

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchHoldingsPrice')
            ->once()
            ->andReturn(['price' => 165.0, 'resolved_symbol' => 'VWCE.DE']);
        $this->app->instance(KluisMarketDataService::class, $market);

        $summary = app(VaultService::class)->holdingsSummary($user);

        $this->assertSame(47.1105, $summary->shares);
        $this->assertEqualsWithDelta(7504.49, $summary->costBasis, 0.01);
        $this->assertEqualsWithDelta(7773.23, (float) $summary->holdingsValue, 0.01);
        $this->assertSame('VWCE.DE', $summary->priceSymbol);
        $this->assertNotNull($summary->unrealizedPnl);
        $this->assertTrue($summary->unrealizedPnl > 0);
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
        $market->shouldReceive('fetchReading')->once()->with(Mockery::type(VaultSetting::class), false)->andReturn($reading);
        $this->app->instance(KluisMarketDataService::class, $market);

        $result = app(VaultService::class)->reading($settings);

        $this->assertSame($reading, $result);
    }

    private function mockFreshReading(KluisThermometerReading $reading): void
    {
        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')->andReturn($reading);
        $market->shouldReceive('fetchHoldingsPrice')->andReturn(null);
        $this->app->instance(KluisMarketDataService::class, $market);
    }
}
