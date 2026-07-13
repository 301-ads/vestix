<?php

namespace Tests\Feature;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Filament\Pages\EditUserProfile;
use App\Models\User;
use App\Models\UserAlertPreference;
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
            ->assertSee('Risico & Earnings Waarschuwingen');
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
}
