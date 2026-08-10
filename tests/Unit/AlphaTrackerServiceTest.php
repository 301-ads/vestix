<?php

namespace Tests\Unit;

use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\AlphaTrackerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlphaTrackerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_growth_curve_calculates_portfolio_and_benchmark_percentages(): void
    {
        $user = User::factory()->create();
        $this->seedSnapshots($user);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);

        $this->assertGreaterThanOrEqual(3, count($curve));
        $this->assertSame(0.0, $curve[0]['portfolio_pct']);
        $this->assertSame(0.0, $curve[0]['benchmark_pct']);

        $latest = collect($curve)->last();
        $this->assertEqualsWithDelta(6.35, $latest['portfolio_pct'], 0.01);
        $this->assertEqualsWithDelta(4.0, $latest['benchmark_pct'], 0.01);
        $this->assertEqualsWithDelta(2.35, $latest['alpha_pct'], 0.01);
    }

    public function test_growth_curve_densifies_daily_spy_between_snapshots(): void
    {
        $this->mock(\App\Contracts\DailyBarProvider::class, function ($mock): void {
            $mock->shouldReceive('fetchRecentBars')->andReturn([
                'today' => ['open' => 500, 'high' => 520, 'low' => 490, 'close' => 520, 'volume' => 1],
                'adv30' => 1.0,
                'bars' => [
                    ['date' => '2026-01-04', 'open' => 500, 'high' => 500, 'low' => 500, 'close' => 500, 'volume' => 1],
                    ['date' => '2026-01-05', 'open' => 490, 'high' => 490, 'low' => 490, 'close' => 490, 'volume' => 1],
                    ['date' => '2026-01-11', 'open' => 510, 'high' => 510, 'low' => 510, 'close' => 510, 'volume' => 1],
                    ['date' => '2026-01-18', 'open' => 520, 'high' => 520, 'low' => 520, 'close' => 520, 'volume' => 1],
                ],
            ]);
        });

        $user = User::factory()->create();
        $this->seedSnapshots($user);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);
        $byDate = collect($curve)->keyBy('date');

        $this->assertTrue($byDate->has('2026-01-05'));
        $this->assertEqualsWithDelta(-2.0, $byDate['2026-01-05']['benchmark_pct'], 0.01);
        // Portfolio carried forward from Jan 4 until the next real snapshot.
        $this->assertSame(0.0, $byDate['2026-01-05']['portfolio_pct']);
        $this->assertNull($byDate['2026-01-05']['amount']);
    }

    public function test_ytd_stats_returns_alpha_difference(): void
    {
        $user = User::factory()->create();
        $this->seedSnapshots($user);

        $stats = app(AlphaTrackerService::class)->ytdStats($user);

        $this->assertEqualsWithDelta(6.35, $stats['portfolio_ytd'], 0.01);
        $this->assertEqualsWithDelta(4.0, $stats['benchmark_ytd'], 0.01);
        $this->assertEqualsWithDelta(2.35, $stats['alpha_ytd'], 0.01);
    }

    public function test_has_enough_snapshots_requires_two_points(): void
    {
        $user = User::factory()->create();
        $service = app(AlphaTrackerService::class);

        $this->assertFalse($service->hasEnoughSnapshots($user));

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-01-04',
            'recorded_at' => now(),
        ]);

        $this->assertFalse($service->hasEnoughSnapshots($user));

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10500,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-01-11',
            'recorded_at' => now(),
        ]);

        $this->assertTrue($service->hasEnoughSnapshots($user));
    }

    private function seedSnapshots(User $user): void
    {
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
            'amount' => 10300,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 510,
            'recorded_on' => '2026-01-11',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10635,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-01-18',
            'recorded_at' => now(),
        ]);
    }
}
