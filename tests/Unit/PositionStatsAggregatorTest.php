<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\Squad;
use App\Models\StrategyTag;
use App\Models\User;
use App\Services\PositionStatsAggregator;
use App\Services\StrategyAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionStatsAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaderboard_ranking_order(): void
    {
        $squad = Squad::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $squad->users()->attach([$userA->id, $userB->id]);

        $tag = StrategyTag::query()->first();

        foreach ([1, 1, 0] as $i => $pnl) {
            Position::factory()->create([
                'user_id' => $userA->id,
                'status' => 'closed',
                'entry_price' => 100,
                'exit_price' => 100 + $pnl,
                'closed_at' => now()->subDays(10 - $i),
                'quantity' => 1,
                'strategy_tag_id' => $tag?->id,
            ]);
        }

        foreach ([1, 0, 0] as $i => $pnl) {
            Position::factory()->create([
                'user_id' => $userB->id,
                'status' => 'closed',
                'entry_price' => 100,
                'exit_price' => 100 + $pnl,
                'closed_at' => now()->subDays(10 - $i),
                'quantity' => 1,
                'strategy_tag_id' => $tag?->id,
            ]);
        }

        $aggregator = app(PositionStatsAggregator::class);
        $aggregator->rebuildForSquad($squad);

        $ranked = $aggregator->rankedStatsForSquad($squad->id);

        $this->assertCount(2, $ranked);
        $this->assertEquals($userA->id, $ranked->first()->user_id);
        $this->assertEquals(1, $ranked->first()->rank);
    }

    public function test_strategy_analytics_expectancy(): void
    {
        $user = User::factory()->create();
        $tag = StrategyTag::query()->first();

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 110,
            'closed_at' => now()->subDays(2),
            'quantity' => 1,
            'strategy_tag_id' => $tag?->id,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 90,
            'closed_at' => now()->subDay(),
            'quantity' => 1,
            'strategy_tag_id' => $tag?->id,
        ]);

        $analytics = app(StrategyAnalyticsService::class);
        $stats = $analytics->overallStats($user->id);

        $this->assertEquals(2, $stats['total_trades']);
        $this->assertEquals(50.0, $stats['win_rate']);
        $this->assertGreaterThan(0, $stats['max_drawdown']);
    }
}
