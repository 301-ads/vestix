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

        $this->assertCount(3, $curve);
        $this->assertSame(0.0, $curve[0]['portfolio_pct']);
        $this->assertSame(0.0, $curve[0]['benchmark_pct']);
        $this->assertEqualsWithDelta(6.35, $curve[2]['portfolio_pct'], 0.01);
        $this->assertEqualsWithDelta(4.0, $curve[2]['benchmark_pct'], 0.01);
        $this->assertEqualsWithDelta(2.35, $curve[2]['alpha_pct'], 0.01);
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
