<?php

namespace Tests\Feature;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Filament\Pages\EditUserProfile;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\BenchmarkCloseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_shows_tabbed_sections(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->assertOk()
            ->assertSee('Algemeen & Beveiliging')
            ->assertSee('Trading Voorkeuren')
            ->assertSee('Telegram & Alerts')
            ->assertSee('Beveiliging')
            ->assertSee('Mijn broker')
            ->assertSee('Order & Winst Executie')
            ->assertSee('Pre-Market & Kansen')
            ->assertSee('Risico & Earnings Waarschuwingen')
            ->assertSee('Social & Squads')
            ->assertSee('Interactive Brokers');
    }

    public function test_profile_hydrates_risk_percent_toggle_for_decimal_cast_value(): void
    {
        $user = User::factory()->create([
            'default_risk_percent' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->assertSchemaStateSet([
                'default_risk_percent' => '1',
            ]);
    }

    public function test_profile_save_creates_bankroll_snapshot(): void
    {
        $this->mock(BenchmarkCloseResolver::class, function ($mock): void {
            $mock->shouldReceive('benchmarkTicker')->andReturn('SPY');
            $mock->shouldReceive('resolveTradingDayClose')->andReturn(550.25);
        });

        $user = User::factory()->create([
            'primary_broker' => Broker::Revolut,
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'trading_bankroll' => 10634.60,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
        ]);

        $this->assertEquals(10634.60, (float) $user->fresh()->trading_bankroll);
    }

    public function test_ibkr_manual_bankroll_override_updates_deployable_fields(): void
    {
        $this->mock(BenchmarkCloseResolver::class, function ($mock): void {
            $mock->shouldReceive('benchmarkTicker')->andReturn('SPY');
            $mock->shouldReceive('resolveTradingDayClose')->andReturn(550.25);
        });

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 4555.29,
            'ibkr_net_liquidation' => 4555.29,
            'ibkr_settled_cash' => 4555.29,
            'ibkr_available_funds' => 4555.29,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
            'default_risk_percent' => 1.5,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'trading_bankroll' => 6840.89,
                'ibkr_available_funds' => 5009.03,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertEquals(6840.89, (float) $user->trading_bankroll);
        $this->assertEquals(6840.89, (float) $user->ibkr_net_liquidation);
        $this->assertEquals(5009.03, (float) $user->ibkr_settled_cash);
        $this->assertEquals(5009.03, (float) $user->ibkr_available_funds);
    }

    public function test_profile_saves_merged_alert_preferences(): void
    {
        $user = User::factory()->create([
            'primary_broker' => Broker::Revolut,
            'trading_bankroll' => 10000,
            'default_risk_percent' => 1,
        ]);
        UserAlertPreference::ensureDefaultsForUser($user);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'alert_events_order' => [AlertEventType::StoppedOut->value],
                'alert_events_premarket' => [AlertEventType::PremarketGapRisk->value],
                'alert_events_risk' => [],
                'alert_events_squad' => [AlertEventType::SquadCopyAlert->value],
                'alert_events_digest' => false,
                'daily_digest_time' => '20:30',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $preference = $user->fresh()->alertPreferences()->where('channel_type', 'telegram')->first();

        $this->assertNotNull($preference);
        $this->assertSame(
            [
                AlertEventType::StoppedOut->value,
                AlertEventType::PremarketGapRisk->value,
                AlertEventType::SquadCopyAlert->value,
            ],
            $preference->active_events,
        );
        $this->assertSame('20:30', $preference->daily_digest_time);
        $this->assertNotContains(AlertEventType::DailyDigest->value, $preference->active_events);
    }

    public function test_profile_shows_ibkr_sync_status_when_synced(): void
    {
        $user = User::factory()->create([
            'ibkr_last_success_at' => now(),
            'ibkr_base_currency' => 'USD',
            'ibkr_settled_cash' => 3800.50,
            'ibkr_available_funds' => 4200,
            'ibkr_data_stale' => false,
            'trading_bankroll' => 10634.60,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->assertOk()
            ->assertSee('IBKR sync')
            ->assertSee('Synced')
            ->assertSee('deployable');
    }
}
