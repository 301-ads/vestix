<?php

namespace Tests\Feature;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Filament\Pages\EditUserProfile;
use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\BenchmarkCloseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->assertSee('IBKR Flex-koppeling')
            ->assertSee('Telegram & Alerts')
            ->assertSee('Beveiliging')
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
            'primary_broker' => Broker::Ibkr,
            'trading_bankroll' => 10000,
            'ibkr_net_liquidation' => 10000,
            'default_risk_percent' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'ibkr_net_liquidation' => 10634.60,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'amount' => 10634.60,
            'benchmark_ticker' => 'SPY',
        ]);

        $this->assertEquals(10634.60, (float) $user->fresh()->trading_bankroll);
        $this->assertEquals(10634.60, (float) $user->fresh()->ibkr_net_liquidation);
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
                'ibkr_net_liquidation' => 6840.89,
                'ibkr_available_funds' => 5009.03,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertEquals(6840.89, (float) $user->trading_bankroll);
        $this->assertEquals(6840.89, (float) $user->ibkr_net_liquidation);
        $this->assertEquals(4555.29, (float) $user->ibkr_settled_cash);
        $this->assertEquals(5009.03, (float) $user->ibkr_available_funds);
    }

    public function test_nlv_save_updates_alpha_snapshot(): void
    {
        $this->mock(BenchmarkCloseResolver::class, function ($mock): void {
            $mock->shouldReceive('benchmarkTicker')->andReturn('SPY');
            $mock->shouldReceive('resolveTradingDayClose')->andReturn(550.25);
        });

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'ibkr_net_liquidation' => 8000,
            'trading_bankroll' => 8000,
            'default_risk_percent' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'ibkr_net_liquidation' => 9609.10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertEquals(9609.10, (float) $user->ibkr_net_liquidation);
        $this->assertEquals(9609.10, (float) $user->trading_bankroll);
        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'amount' => 9609.10,
        ]);
    }

    public function test_profile_nlv_save_on_sunday_writes_friday_alpha_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 20:00:00', 'Europe/Amsterdam'));

        $this->mock(BenchmarkCloseResolver::class, function ($mock): void {
            $mock->shouldReceive('benchmarkTicker')->andReturn('SPY');
            $mock->shouldReceive('resolveTradingDayClose')->andReturn(776.34);
        });

        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
            'ibkr_net_liquidation' => 7993.10,
            'trading_bankroll' => 7993.10,
            'default_risk_percent' => 1,
        ]);
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->fillForm([
                'ibkr_net_liquidation' => 7993.10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $snapshot = BankrollSnapshot::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('2026-08-14', $snapshot->recorded_on->toDateString());
        $this->assertEqualsWithDelta(7993.10, (float) $snapshot->amount, 0.01);

        Carbon::setTestNow();
    }

    public function test_profile_saves_merged_alert_preferences(): void
    {
        $user = User::factory()->create([
            'primary_broker' => Broker::Ibkr,
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

        $expected = [
            AlertEventType::StoppedOut->value,
            AlertEventType::PremarketGapRisk->value,
            AlertEventType::SquadCopyAlert->value,
        ];

        foreach (['telegram', 'webpush'] as $channel) {
            $preference = $user->fresh()->alertPreferences()->where('channel_type', $channel)->first();

            $this->assertNotNull($preference, "Missing preference for {$channel}");
            $this->assertSame($expected, $preference->active_events);
            $this->assertSame('20:30', $preference->daily_digest_time);
            $this->assertNotContains(AlertEventType::DailyDigest->value, $preference->active_events);
        }
    }

    public function test_profile_shows_push_notifications_section(): void
    {
        config([
            'services.webpush.subject' => 'mailto:test@vestix.test',
            'services.webpush.public_key' => 'BBLcZE3DkZ1llsZ8lKPk1XGIp_NO_s0etD_ib5As_z9drjc6AR2Ls3Rt4QWTvwqEPcB0yzWFTE3VM6n5ci9vrrI',
            'services.webpush.private_key' => 'o5Aba0KZpcB5BeT0Hlsc_UEXxW7f-DmOBK05sZmz4Oc',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->assertOk()
            ->assertSee('Push-notificaties')
            ->assertSee('Zet push aan');
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
        $user->storeIbkrFlexCredentials('token', '1575288');
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->assertOk()
            ->assertSee('IBKR sync')
            ->assertSee('Synced')
            ->assertSee('Gekoppeld')
            ->assertSee('1575288')
            ->assertDontSee('secret-token')
            ->assertSee('deployable')
            ->assertSee('risicopie op');
    }

    public function test_profile_can_save_ibkr_flex_credentials_without_exposing_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(EditUserProfile::class)
            ->callAction('save_ibkr_flex', data: [
                'token' => 'super-secret-flex-token',
                'query_id' => '424242',
            ])
            ->assertHasNoActionErrors()
            ->assertSee('Gekoppeld')
            ->assertSee('424242')
            ->assertDontSee('super-secret-flex-token');

        $user->refresh();
        $this->assertTrue($user->hasIbkrFlexConnection());
        $this->assertSame('super-secret-flex-token', $user->ibkrFlexCredentials()['token']);
        $this->assertSame(Broker::Ibkr, $user->primary_broker);
    }
}
