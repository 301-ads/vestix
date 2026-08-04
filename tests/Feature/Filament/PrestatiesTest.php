<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Prestaties;
use App\Filament\Widgets\AlphaTrackerChart;
use App\Filament\Widgets\AlphaTrackerStatsWidget;
use App\Filament\Widgets\DirectionPnlSplitWidget;
use App\Filament\Widgets\EdgeAnalyticsWidget;
use App\Filament\Widgets\KluisComingSoonWidget;
use App\Filament\Widgets\KluisEquityChart;
use App\Filament\Widgets\KluisStatsWidget;
use App\Filament\Widgets\PerformanceComingSoonWidget;
use App\Filament\Widgets\GradePerformanceChart;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrestatiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_prestaties_page_renders(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Prestaties::class)
            ->assertOk()
            ->assertSee('Swing Sniper')
            ->assertSee('Vestix Kluis')
            ->assertSee('Alpha Tracker')
            ->assertSee('Naar dashboard');
    }

    public function test_prestaties_shows_bankroll_cta_without_enough_snapshots(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Prestaties::class)
            ->assertSee('Alpha Tracker')
            ->assertSee('Naar dashboard')
            ->assertDontSee('Jouw Rendement (YTD)');
    }

    public function test_prestaties_widget_order(): void
    {
        $page = new Prestaties;

        $this->assertSame([
            AlphaTrackerStatsWidget::class,
            DirectionPnlSplitWidget::class,
            AlphaTrackerChart::class,
            PerformanceComingSoonWidget::class,
            EdgeAnalyticsWidget::class,
            GradePerformanceChart::class,
        ], $page->getSwingWidgets());

        $this->assertSame([
            KluisStatsWidget::class,
            KluisEquityChart::class,
            KluisComingSoonWidget::class,
        ], $page->getKluisWidgets());
    }

    public function test_prestaties_shows_alpha_tracker_when_two_snapshots_exist(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-01-04',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10635,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-01-11',
            'recorded_at' => now(),
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(Prestaties::class)
            ->assertSee('Jouw Rendement (YTD)')
            ->assertSee('NLV-groei incl. open MTM')
            ->assertSee('Jouw Alpha')
            ->assertDontSee('Naar dashboard');
    }

    public function test_direction_pnl_widget_visible_with_open_positions_only(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();

        Position::factory()->for($user)->create([
            'ticker' => 'OPEN',
            'status' => 'open',
            'entry_price' => 100,
            'quantity' => 5,
            'latest_close_price' => 110,
            'current_sl' => 90,
        ]);

        $this->actingAsFilamentUser($user, $squad);

        $this->assertTrue(DirectionPnlSplitWidget::canView());

        Livewire::test(DirectionPnlSplitWidget::class)
            ->assertOk()
            ->assertSee('Totale trading P&L')
            ->assertSee('Open (MTM)');
    }
}
