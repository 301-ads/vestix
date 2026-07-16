<?php

namespace Tests\Unit;

use App\Models\Position;
use App\Models\User;
use App\Services\StrategyAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyPositionFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_legacy_scope_excludes_legacy_positions(): void
    {
        $user = User::factory()->create();

        Position::factory()->for($user)->closed()->create(['ticker' => 'CLEAN', 'is_legacy' => false]);
        Position::factory()->for($user)->closed()->legacy()->create(['ticker' => 'OLD']);

        $this->assertSame(1, Position::query()->nonLegacy()->count());
        $this->assertSame(1, Position::query()->legacy()->count());
        $this->assertSame('CLEAN', Position::query()->nonLegacy()->value('ticker'));
    }

    public function test_strategy_analytics_ignores_legacy_closed_trades(): void
    {
        $user = User::factory()->create();
        $analytics = app(StrategyAnalyticsService::class);

        Position::factory()->for($user)->closed()->create([
            'entry_price' => 100,
            'exit_price' => 110,
            'quantity' => 10,
            'is_legacy' => false,
            'freeride_secured_at' => now(),
        ]);
        Position::factory()->for($user)->closed()->legacy()->create([
            'entry_price' => 100,
            'exit_price' => 50,
            'quantity' => 10,
            'freeride_secured_at' => null,
        ]);

        $this->assertCount(1, $analytics->closedTradesForUser($user->id));
        $this->assertSame(100.0, $analytics->freerideHitRate($user->id)['hit_rate']);
    }
}
