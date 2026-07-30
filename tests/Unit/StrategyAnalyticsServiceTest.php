<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\StrategyTag;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private StrategyAnalyticsService $analytics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(StrategyAnalyticsService::class);
    }

    public function test_profit_factor_divides_total_wins_by_total_losses(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
        ]);
        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 10,
        ]);

        $this->assertSame(1.0, $this->analytics->profitFactor($user->id));
    }

    public function test_profit_factor_returns_null_when_there_are_no_losses_but_wins_exist(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
        ]);

        $this->assertNull($this->analytics->profitFactor($user->id));
    }

    public function test_biggest_loss_includes_archive_investment_percentage(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'ticker' => 'LOSS',
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 10,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'WIN',
            'entry_price' => 100,
            'exit_price' => 105,
            'quantity' => 10,
        ]);

        $biggestLoss = $this->analytics->biggestLoss($user->id);

        $this->assertNotNull($biggestLoss);
        $this->assertSame('LOSS', $biggestLoss['ticker']);
        $this->assertSame(-100.0, $biggestLoss['dollars']);
        $this->assertSame(5.0, $biggestLoss['pct_of_archive_investment']);
    }

    public function test_freeride_hitrate_counts_only_freeride_secured_trades_as_hits(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'freeride_secured_at' => now(),
        ]);
        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'freeride_secured_at' => null,
        ]);

        $hitRate = $this->analytics->freerideHitRate($user->id);

        $this->assertSame(50.0, $hitRate['hit_rate']);
        $this->assertSame(1, $hitRate['hits']);
        $this->assertSame(2, $hitRate['total']);
        $this->assertSame(50.0, $hitRate['miss_rate']);
    }

    public function test_freeride_hitrate_treats_profitable_trade_without_freeride_as_miss(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 105,
            'quantity' => 10,
            'freeride_secured_at' => null,
        ]);

        $hitRate = $this->analytics->freerideHitRate($user->id);

        $this->assertSame(0.0, $hitRate['hit_rate']);
        $this->assertSame(0, $hitRate['hits']);
        $this->assertSame(1, $hitRate['total']);
    }

    public function test_closed_trades_include_null_and_inactive_strategy_tags_for_unlock(): void
    {
        $user = User::factory()->create();
        $trampolineId = (int) StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');
        $emaId = (int) StrategyTag::query()->where('slug', 'ema-200-bounce')->value('id');

        config(['vestix.strategy_coach.min_closed_trades' => 3]);

        Position::factory()->for($user)->closed()->create([
            'ticker' => 'TRAM',
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'strategy_tag_id' => $trampolineId,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'EMA',
            'entry_price' => 100,
            'exit_price' => 50,
            'quantity' => 10,
            'strategy_tag_id' => $emaId,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'NONE',
            'entry_price' => 100,
            'exit_price' => 105,
            'quantity' => 10,
            'strategy_tag_id' => null,
        ]);

        $trades = $this->analytics->closedTradesForUser($user->id);
        $activeOnly = $this->analytics->closedTradesForUser($user->id, activeStrategyTagsOnly: true);
        $stats = $this->analytics->overallStats($user->id);
        $perTag = $this->analytics->statsPerTag($user->id);

        $this->assertCount(3, $trades);
        $this->assertSame(['EMA', 'NONE', 'TRAM'], $trades->pluck('ticker')->sort()->values()->all());
        $this->assertCount(1, $activeOnly);
        $this->assertSame('TRAM', $activeOnly->first()->ticker);
        $this->assertTrue($this->analytics->hasEnoughTrades($user->id));
        $this->assertSame(0, $this->analytics->tradesUntilCoach($user->id));
        $this->assertSame(3, $stats['total_trades']);
        $this->assertCount(1, $perTag);
        $this->assertSame('Trampoline Bounce', $perTag[0]['tag_name']);
    }

    public function test_stats_by_grade_prefers_active_strategy_tags_then_falls_back(): void
    {
        $user = User::factory()->create();
        $trampolineId = (int) StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');
        $emaId = (int) StrategyTag::query()->where('slug', 'ema-200-bounce')->value('id');

        Position::factory()->for($user)->closed()->create([
            'ticker' => 'TRAM',
            'strategy_tag_id' => $trampolineId,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'last_setup_score' => 7,
        ]);
        Position::factory()->for($user)->closed()->create([
            'ticker' => 'EMA',
            'strategy_tag_id' => $emaId,
            'entry_price' => 100,
            'exit_price' => 50,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a' => true,
        ]);

        $byGrade = $this->analytics->statsByGrade($user->id);

        $this->assertSame(['B'], array_column($byGrade, 'grade'));
        $this->assertSame(1, $byGrade[0]['trades']);

        Position::query()->where('ticker', 'TRAM')->delete();

        $fallback = $this->analytics->statsByGrade($user->id);

        $this->assertSame(['A++'], array_column($fallback, 'grade'));
        $this->assertSame(1, $fallback[0]['trades']);
    }

    public function test_stats_by_grade_groups_win_rate_and_expectancy(): void
    {
        $user = User::factory()->create();
        $trampolineId = (int) StrategyTag::query()->where('slug', 'trampoline-bounce')->value('id');

        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $trampolineId,
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a' => true,
        ]);
        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $trampolineId,
            'entry_price' => 100,
            'exit_price' => 90,
            'quantity' => 10,
            'last_setup_score' => 10,
            'trader_promoted_a_plus' => true,
            'trader_promoted_a' => true,
        ]);
        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $trampolineId,
            'entry_price' => 100,
            'exit_price' => 108,
            'quantity' => 10,
            'last_setup_score' => 7,
        ]);
        Position::factory()->for($user)->closed()->create([
            'strategy_tag_id' => $trampolineId,
            'entry_price' => 100,
            'exit_price' => 112,
            'quantity' => 10,
            'buy_stop_review_setup_grade' => 'A',
            'last_setup_score' => null,
        ]);

        $byGrade = $this->analytics->statsByGrade($user->id);

        $this->assertSame(['A++', 'A', 'B'], array_column($byGrade, 'grade'));
        $this->assertSame(2, $byGrade[0]['trades']);
        $this->assertSame(50.0, $byGrade[0]['win_rate']);
        $this->assertSame(1, $byGrade[1]['trades']);
        $this->assertSame(100.0, $byGrade[1]['win_rate']);
        $this->assertSame(1, $byGrade[2]['trades']);
        $this->assertSame(100.0, $byGrade[2]['win_rate']);
    }
}
