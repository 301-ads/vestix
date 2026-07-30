<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\BankrollUpdateWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class BankrollUpdateWidgetIbkrTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_when_ibkr_sync_is_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_last_success_at' => Carbon::parse('2026-07-14 10:00:00'),
            'ibkr_data_stale' => true,
        ]);

        $this->actingAs($user);

        $this->assertTrue(BankrollUpdateWidget::canView());

        Livewire::test(BankrollUpdateWidget::class)
            ->assertSet('ibkrStale', true)
            ->assertSee('IBKR sync stale');

        Carbon::setTestNow();
    }

    public function test_widget_hides_when_ibkr_sync_is_fresh(): void
    {
        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_last_success_at' => now(),
            'ibkr_data_stale' => false,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BankrollUpdateWidget::canView());
    }

    public function test_widget_hides_when_flex_is_configured_but_never_synced(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00')); // Wednesday — bankroll update not due

        config([
            'vestix.ibkr.reader' => 'flex',
            'vestix.bankroll_tracker.source' => 'manual',
        ]);

        $user = User::factory()->create([
            'trading_bankroll' => 25000,
            'ibkr_last_success_at' => null,
            'ibkr_data_stale' => false,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BankrollUpdateWidget::canView());

        Carbon::setTestNow();
    }
}
