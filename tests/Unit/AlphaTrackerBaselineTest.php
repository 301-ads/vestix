<?php

namespace Tests\Unit;

use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\AlphaTrackerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlphaTrackerBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_growth_curve_uses_baseline_capital_and_ignores_pre_baseline_snapshots(): void
    {
        $user = User::factory()->create([
            'baseline_capital' => 3428.40,
            'baseline_date' => '2026-07-16',
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 9000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 400,
            'recorded_on' => '2026-01-04',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3600.00,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 510,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $curve = app(AlphaTrackerService::class)->growthCurve($user);

        $this->assertCount(2, $curve);
        $this->assertSame('2026-07-16', $curve[0]['date']);
        $this->assertSame(0.0, $curve[0]['portfolio_pct']);
        $this->assertEqualsWithDelta(5.01, $curve[1]['portfolio_pct'], 0.01);
    }

    public function test_ytd_stats_anchor_to_baseline_capital(): void
    {
        $user = User::factory()->create([
            'baseline_capital' => 3428.40,
            'baseline_date' => '2026-07-16',
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3428.40,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-16',
            'recorded_at' => now(),
        ]);

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 3600.00,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 520,
            'recorded_on' => '2026-07-23',
            'recorded_at' => now(),
        ]);

        $stats = app(AlphaTrackerService::class)->ytdStats($user);

        $this->assertEqualsWithDelta(5.01, $stats['portfolio_ytd'], 0.01);
        $this->assertEqualsWithDelta(4.0, $stats['benchmark_ytd'], 0.01);
        $this->assertEqualsWithDelta(1.01, $stats['alpha_ytd'], 0.01);
    }
}
