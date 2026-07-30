<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\EdgeAnalyticsWidget;
use App\Models\Position;
use App\Models\StrategyTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EdgeAnalyticsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_scorecard_grade_badges_and_hides_ungraded_dash_row(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $tagId = (int) StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');

        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $tagId,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'buy_stop_review_setup_grade' => 'A++',
        ]);
        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $tagId,
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 10,
            'last_setup_score' => null,
            'buy_stop_review_setup_grade' => null,
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(EdgeAnalyticsWidget::class)
            ->assertOk()
            ->assertSee('Edge analytics')
            ->assertSee('Per setup-grade')
            ->assertSeeHtml('scout-scorecard-hud-grade-badge--a-plus')
            ->assertSee('1 trade(s) zonder setup-grade')
            ->assertDontSeeHtml('fi-badge-label">—</span>');
    }

    public function test_ungraded_only_trades_skip_grade_table(): void
    {
        ['user' => $user, 'squad' => $squad] = $this->createUserWithSquad();
        $tagId = (int) StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');

        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $tagId,
            'entry_price' => 100,
            'exit_price' => 105,
            'quantity' => 5,
            'last_setup_score' => null,
        ]);

        $this->actingAsFilamentUser($user, $squad);

        Livewire::test(EdgeAnalyticsWidget::class)
            ->assertOk()
            ->assertSee('Win rate')
            ->assertSee('Nog geen setup-grades')
            ->assertDontSee('Per setup-grade');
    }
}
