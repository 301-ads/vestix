<?php

namespace Tests\Feature\Filament;

use App\Enums\TradeDirection;
use App\Filament\Pages\StrategyCoach;
use App\Filament\Widgets\PortfolioCoachInsightsWidget;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VestixCoachTest extends TestCase
{
    use RefreshDatabase;

    public function test_coach_page_is_labeled_vestix_coach(): void
    {
        $this->authenticateFilament();

        Livewire::test(StrategyCoach::class)
            ->assertSee('Vestix Coach')
            ->assertSee('Portfolio Coach')
            ->assertSee('Edge-analyse')
            ->assertSee('Alles')
            ->assertSee('Longs')
            ->assertSee('Shorts');
    }

    public function test_local_demo_preview_shows_fake_edge_stats(): void
    {
        $this->authenticateFilament();

        config([
            'vestix.strategy_coach.demo_preview' => true,
            'vestix.strategy_coach.force_demo_in_tests' => true,
        ]);

        Livewire::test(StrategyCoach::class)
            ->assertSee('lokale demo-data');

        Livewire::test(\App\Filament\Widgets\StrategyCoachStatsWidget::class)
            ->assertSee('24')
            ->assertSee('62.5%')
            ->assertSee('1.85%');
    }

    public function test_portfolio_coach_widget_shows_sector_concentration(): void
    {
        $user = $this->authenticateFilament();

        Position::factory()->for($user)->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'direction' => TradeDirection::Long,
            'sector_etf' => 'XLF',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 102.00,
        ]);

        Livewire::test(PortfolioCoachInsightsWidget::class)
            ->assertSee('Sector XLF vol')
            ->assertSee('BAC')
            ->assertSee('risk-on');
    }

    public function test_portfolio_coach_widget_shows_long_short_strip(): void
    {
        $user = $this->authenticateFilament();
        $user->update(['is_short_enabled' => true]);

        Position::factory()->for($user)->create([
            'ticker' => 'AAA',
            'status' => 'open',
            'direction' => TradeDirection::Long,
            'sector_etf' => 'XLK',
            'entry_price' => 100.00,
            'current_sl' => 95.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        Position::factory()->for($user)->create([
            'ticker' => 'BBB',
            'status' => 'open',
            'direction' => TradeDirection::Short,
            'sector_etf' => 'XLE',
            'entry_price' => 100.00,
            'current_sl' => 105.00,
            'quantity' => 10,
            'latest_close_price' => 100.00,
        ]);

        Livewire::test(PortfolioCoachInsightsWidget::class)
            ->assertSee('1 long / 1 short');
    }
}
