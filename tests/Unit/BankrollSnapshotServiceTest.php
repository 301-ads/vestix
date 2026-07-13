<?php

namespace Tests\Unit;

use App\Models\BankrollSnapshot;
use App\Models\User;
use App\Services\BankrollSnapshotService;
use App\Services\BenchmarkCloseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class BankrollSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_record_snapshot_syncs_trading_bankroll_and_stores_benchmark(): void
    {
        $resolver = Mockery::mock(BenchmarkCloseResolver::class);
        $resolver->shouldReceive('benchmarkTicker')->andReturn('SPY');
        $resolver->shouldReceive('resolveTradingDayClose')->once()->andReturn(550.25);

        $service = new BankrollSnapshotService($resolver);
        $user = User::factory()->create(['trading_bankroll' => 9000]);

        $snapshot = $service->recordSnapshot($user, 10634.60, Carbon::parse('2026-07-12', 'Europe/Amsterdam'));

        $this->assertSame('10634.60', $snapshot->amount);
        $this->assertSame('550.2500', $snapshot->benchmark_close);
        $this->assertSame('SPY', $snapshot->benchmark_ticker);
        $this->assertEquals(10634.60, (float) $user->fresh()->trading_bankroll);
    }

    public function test_is_update_due_on_saturday_without_weekly_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-06-28',
            'recorded_at' => now(),
        ]);

        $this->assertTrue($service->isUpdateDue($user));
    }

    public function test_is_not_due_when_snapshot_exists_this_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-12 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-07-12',
            'recorded_at' => now(),
        ]);

        $this->assertFalse($service->isUpdateDue($user));
    }

    public function test_is_due_when_last_snapshot_is_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Amsterdam'));

        $service = app(BankrollSnapshotService::class);
        $user = User::factory()->create();

        BankrollSnapshot::query()->create([
            'user_id' => $user->id,
            'amount' => 10000,
            'benchmark_ticker' => 'SPY',
            'benchmark_close' => 500,
            'recorded_on' => '2026-06-20',
            'recorded_at' => now(),
        ]);

        $this->assertTrue($service->isUpdateDue($user));
    }
}
