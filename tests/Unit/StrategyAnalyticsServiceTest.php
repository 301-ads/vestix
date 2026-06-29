<?php

namespace Tests\Unit;

use App\Models\Position;
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
}
