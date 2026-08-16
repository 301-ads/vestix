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

    public function test_stale_escape_hatch_unblocks_smart_sizing_bankroll(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

        config([
            'vestix.ibkr.reader' => 'flex',
            'vestix.ibkr.block_automation_when_stale' => true,
        ]);

        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_net_liquidation' => 10000,
            'ibkr_available_funds' => 10000,
            'ibkr_settled_cash' => 10000,
            'ibkr_last_success_at' => Carbon::parse('2026-07-14 10:00:00'),
            'ibkr_data_stale' => true,
            'default_risk_percent' => 1.5,
        ]);

        $this->actingAs($user);

        $this->assertSame(0.0, app(\App\Services\SmartAllocationService::class)->resolveSizingBankroll($user));

        Livewire::test(BankrollUpdateWidget::class)
            ->assertSet('ibkrStale', true)
            ->set('bankrollAmount', '12500.50')
            ->call('saveBankroll')
            ->assertHasNoErrors()
            ->assertRedirect();

        $user->refresh();

        $this->assertFalse((bool) $user->ibkr_data_stale);
        $this->assertEqualsWithDelta(12500.50, (float) $user->ibkr_net_liquidation, 0.01);
        $this->assertEqualsWithDelta(12500.50, (float) $user->ibkr_available_funds, 0.01);
        $this->assertEqualsWithDelta(12500.50, (float) $user->ibkr_settled_cash, 0.01);
        $this->assertFalse(app(\App\Services\Ibkr\IbkrSyncHealth::class)->blocksAutomatedExecution($user));
        $this->assertEqualsWithDelta(
            12500.50,
            app(\App\Services\SmartAllocationService::class)->resolveSizingBankroll($user),
            0.01,
        );

        Carbon::setTestNow();
    }

    public function test_save_bankroll_shows_validation_when_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-17 12:00:00'));

        $user = User::factory()->create([
            'trading_bankroll' => 10000,
            'ibkr_last_success_at' => Carbon::parse('2026-07-14 10:00:00'),
            'ibkr_data_stale' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(BankrollUpdateWidget::class)
            ->set('bankrollAmount', '')
            ->call('saveBankroll')
            ->assertHasErrors(['bankrollAmount']);

        Carbon::setTestNow();
    }
}
