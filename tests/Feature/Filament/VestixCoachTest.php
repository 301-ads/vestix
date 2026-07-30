<?php

namespace Tests\Feature\Filament;

use App\Enums\TradeDirection;
use App\Filament\Pages\StrategyCoach;
use App\Filament\Widgets\EquityCurveChart;
use App\Filament\Widgets\GradePerformanceChart;
use App\Filament\Widgets\PortfolioCoachInsightsWidget;
use App\Filament\Widgets\StrategyCoachStatsWidget;
use App\Models\Position;
use App\Models\StrategyTag;
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

    public function test_coach_widgets_include_grade_performance_chart(): void
    {
        $page = new StrategyCoach;

        $this->assertSame([
            PortfolioCoachInsightsWidget::class,
            StrategyCoachStatsWidget::class,
            EquityCurveChart::class,
            GradePerformanceChart::class,
        ], $page->getWidgets());
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

        Livewire::test(StrategyCoachStatsWidget::class)
            ->assertSee('24')
            ->assertSee('62.5%')
            ->assertSee('1.85%');

        Livewire::test(GradePerformanceChart::class)
            ->assertOk();
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
            ->assertSee('Sector XLF long vol')
            ->assertSee('BAC')
            ->assertSee('risk-on');
    }

    public function test_coach_unlock_counts_archived_closed_trades_without_active_tag(): void
    {
        $user = $this->authenticateFilament();

        config(['vestix.strategy_coach.min_closed_trades' => 3]);

        $emaId = (int) StrategyTag::query()->where('slug', 'ema-200-bounce')->value('id');

        Position::factory()->for($user)->closed()->create([
            'ticker' => 'AAA',
            'strategy_tag_id' => null,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'BBB',
            'strategy_tag_id' => $emaId,
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 10,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'CCC',
            'strategy_tag_id' => null,
            'entry_price' => 100,
            'exit_price' => 105,
            'quantity' => 10,
        ]);

        Livewire::test(StrategyCoachStatsWidget::class)
            ->assertDontSee('Nog 3 trades')
            ->assertDontSee('Nog 20 trades')
            ->assertSee('Gesloten trades')
            ->assertSee('3');
    }
}
