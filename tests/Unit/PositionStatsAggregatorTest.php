<?php

namespace Tests\Unit;

use App\Enums\LeaderboardTrack;
use App\Enums\PositionVisibility;
use App\Enums\SquadRole;
use App\Models\LeaderboardStat;
use App\Models\Position;
use App\Models\Squad;
use App\Models\StrategyTag;
use App\Models\User;
use App\Services\PositionStatsAggregator;
use App\Services\SquadPermissionService;
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

        $ranked = $aggregator->rankedStatsForSquad($squad->id, LeaderboardTrack::Executor);

        $this->assertCount(2, $ranked);
        $this->assertEquals($userA->id, $ranked->first()->user_id);
        $this->assertEquals(1, $ranked->first()->rank);
        $this->assertSame(LeaderboardTrack::Executor, $ranked->first()->track);
    }

    public function test_analyst_track_credits_spotter_for_closed_clones(): void
    {
        ['user' => $analyst, 'squad' => $squad] = $this->createUserWithSquad();
        $sniper = User::factory()->create();
        $squad->users()->attach($sniper);
        app(SquadPermissionService::class)->assignRole($sniper, $squad, SquadRole::Sniper);

        $shared = Position::factory()->for($analyst)->scout()->create([
            'visibility' => PositionVisibility::Squad,
            'squad_id' => $squad->id,
            'ticker' => 'META',
        ]);

        foreach ([10, 5, -2] as $i => $pnl) {
            $clone = $shared->cloneForUser($sniper);
            $clone->update([
                'status' => 'closed',
                'entry_price' => 100,
                'exit_price' => 100 + $pnl,
                'quantity' => 1,
                'closed_at' => now()->subDays(3 - $i),
            ]);
        }

        // Sniper also has personal closed trades that should NOT inflate Analyst for analyst.
        Position::factory()->create([
            'user_id' => $sniper->id,
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 150,
            'closed_at' => now(),
            'quantity' => 1,
        ]);

        $aggregator = app(PositionStatsAggregator::class);
        $aggregator->rebuildForSquad($squad);

        $analystRow = LeaderboardStat::query()
            ->where('squad_id', $squad->id)
            ->where('user_id', $analyst->id)
            ->where('track', LeaderboardTrack::Analyst)
            ->firstOrFail();

        $this->assertSame(3, $analystRow->closed_trades_count);
        $this->assertEquals(1, $analystRow->rank);
        $this->assertGreaterThan(0, (float) $analystRow->win_rate);

        $sniperAnalyst = LeaderboardStat::query()
            ->where('squad_id', $squad->id)
            ->where('user_id', $sniper->id)
            ->where('track', LeaderboardTrack::Analyst)
            ->firstOrFail();

        $this->assertSame(0, $sniperAnalyst->closed_trades_count);

        $executorSniper = LeaderboardStat::query()
            ->where('squad_id', $squad->id)
            ->where('user_id', $sniper->id)
            ->where('track', LeaderboardTrack::Executor)
            ->firstOrFail();

        $this->assertGreaterThanOrEqual(1, $executorSniper->closed_trades_count);
    }

    public function test_strategy_analytics_expectancy(): void
    {
        $user = User::factory()->create();
        $tagId = $this->defaultStrategyTagId();

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 110,
            'closed_at' => now()->subDays(2),
            'quantity' => 1,
            'strategy_tag_id' => $tagId,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'status' => 'closed',
            'entry_price' => 100,
            'exit_price' => 90,
            'closed_at' => now()->subDay(),
            'quantity' => 1,
            'strategy_tag_id' => $tagId,
        ]);

        $analytics = app(StrategyAnalyticsService::class);
        $stats = $analytics->overallStats($user->id);

        $this->assertEquals(2, $stats['total_trades']);
        $this->assertEquals(50.0, $stats['win_rate']);
        $this->assertGreaterThan(0, $stats['max_drawdown']);
    }
}
