<?php

namespace Tests\Feature\Filament;

use App\Enums\KluisClimate;
use App\Enums\VaultTransactionSource;
use App\Filament\Pages\VestixKluis;
use App\Models\VaultDeposit;
use App\Models\VaultTransaction;
use App\Services\Kluis\KluisMarketDataService;
use App\Services\Kluis\VaultService;
use App\Support\Kluis\KluisThermometerReading;
use Database\Seeders\VaultTransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class VestixKluisTest extends TestCase
{
    use RefreshDatabase;

    public function test_kluis_page_renders(): void
    {
        $this->authenticateFilament();

        Livewire::test(VestixKluis::class)
            ->assertOk()
            ->assertSee('Vestix Kluis')
            ->assertSee('Beschikbaar maandbudget')
            ->assertSee('Droog kruit')
            ->assertSee('Aankopen');
    }

    public function test_confirm_month_writes_deposit_and_transaction(): void
    {
        $user = $this->authenticateFilament();

        $reading = new KluisThermometerReading(
            climate: KluisClimate::Neutral,
            deviationPct: 2.5,
            close: 102.5,
            sma200: 100,
            ticker: 'VWCE',
        );

        $market = Mockery::mock(KluisMarketDataService::class);
        $market->shouldReceive('fetchReading')->andReturn($reading);
        $market->shouldReceive('fetchHoldingsPrice')->andReturn(null);
        $this->app->instance(KluisMarketDataService::class, $market);

        Livewire::test(VestixKluis::class)
            ->fillForm(['budget' => 10000])
            ->call('confirmMonthWithFill', app(VaultService::class), [
                'shares' => 97.561,
                'fill_price' => 102.5,
                'etf_amount' => 10000,
                'fee' => 0,
            ])
            ->assertHasNoErrors();

        $this->assertDatabaseHas('vault_deposits', [
            'user_id' => $user->id,
            'etf_amount' => 10000,
            'dry_powder_delta' => 0,
        ]);

        $this->assertSame(1, VaultDeposit::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, VaultTransaction::query()->where('user_id', $user->id)->count());
        $this->assertSame(0.0, (float) app(VaultService::class)->settingsFor($user)->dry_powder_balance);
    }

    public function test_add_historical_purchase_via_service(): void
    {
        $user = $this->authenticateFilament();

        app(VaultService::class)->addHistoricalPurchase($user, [
            'traded_at' => '2026-05-13 11:05:53',
            'shares' => 47.1105,
            'fill_price' => 159.20,
            'etf_amount' => 7499.99,
            'fee' => 4.50,
        ]);

        $this->assertDatabaseHas('vault_transactions', [
            'user_id' => $user->id,
            'shares' => 47.1105,
            'source' => VaultTransactionSource::Historical->value,
        ]);

        Livewire::test(VestixKluis::class)
            ->assertSee('47,1105')
            ->assertSee('Historisch');
    }

    public function test_seed_historical_fills(): void
    {
        $user = $this->authenticateFilament();

        $this->seed(VaultTransactionSeeder::class);

        $this->assertSame(5, VaultTransaction::query()->where('user_id', $user->id)->count());

        $summary = app(VaultService::class)->holdingsSummary($user);
        $this->assertEqualsWithDelta(185.9509, $summary->shares, 0.0001);
        $this->assertEqualsWithDelta(30188.07, $summary->costBasis, 0.01);
    }

    public function test_save_settings_persists_config(): void
    {
        $user = $this->authenticateFilament();

        Livewire::test(VestixKluis::class)
            ->fillForm([
                'budget' => 8000,
                'etf_ticker' => 'vwce',
                'default_monthly_budget' => 8000,
                'overheat_threshold_pct' => 12,
                'crash_threshold_pct' => 12,
                'overheat_invest_fraction' => 40,
                'dip_dry_powder_fraction' => 30,
                'crash_dry_powder_fraction' => 60,
            ])
            ->call('saveSettings')
            ->assertHasNoErrors();

        $settings = app(VaultService::class)->settingsFor($user);

        $this->assertSame('VWCE', $settings->etf_ticker);
        $this->assertSame(8000.0, (float) $settings->default_monthly_budget);
        $this->assertSame(0.4, (float) $settings->overheat_invest_fraction);
    }
}
