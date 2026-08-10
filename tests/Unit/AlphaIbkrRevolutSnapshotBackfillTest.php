<?php

namespace Tests\Unit;

use App\Contracts\DailyBarProvider;
use App\Enums\BankrollCashflowType;
use App\Enums\Broker;
use App\Models\BankrollSnapshot;
use App\Models\Position;
use App\Models\User;
use App\Services\AlphaIbkrRevolutSnapshotBackfill;
use App\Services\BankrollCashflowService;
use App\Services\BankrollSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class AlphaIbkrRevolutSnapshotBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_backfill_adds_halo_and_bac_mtm_on_top_of_ibkr_nlv(): void
    {
        $user = User::factory()->create([
            'baseline_date' => '2026-07-15',
            'revolut_cash' => 0,
            'primary_broker' => Broker::Ibkr,
        ]);

        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Deposit,
            3432.90,
            Carbon::parse('2026-07-15'),
            'IBKR deposit: opening',
        );
        app(BankrollCashflowService::class)->record(
            $user,
            BankrollCashflowType::Withdrawal,
            1081.34,
            Carbon::parse('2026-08-10'),
            'HALO proceeds out',
        );

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'BAC',
            'broker' => Broker::Revolut,
            'status' => 'open',
            'quantity' => 22,
            'entry_price' => 51.50,
            'latest_close_price' => 63.17,
        ]);

        Position::factory()->create([
            'user_id' => $user->id,
            'ticker' => 'HALO',
            'broker' => Broker::Revolut,
            'status' => 'closed',
            'quantity' => 13,
            'entry_price' => 76.06,
            'exit_price' => 83.18,
            'closed_at' => '2026-08-06 14:21:05',
        ]);

        $bars = Mockery::mock(DailyBarProvider::class);
        $bars->shouldReceive('fetchRecentBars')->with('BAC', Mockery::any(), Mockery::any())->andReturn([
            'today' => ['open' => 60, 'high' => 60, 'low' => 60, 'close' => 60, 'volume' => 1],
            'adv30' => 1.0,
            'bars' => [
                ['date' => '2026-07-16', 'open' => 52, 'high' => 52, 'low' => 52, 'close' => 52.0, 'volume' => 1],
                ['date' => '2026-08-05', 'open' => 62, 'high' => 62, 'low' => 62, 'close' => 62.0, 'volume' => 1],
                ['date' => '2026-08-06', 'open' => 63, 'high' => 63, 'low' => 63, 'close' => 63.0, 'volume' => 1],
                ['date' => '2026-08-10', 'open' => 63.17, 'high' => 63.17, 'low' => 63.17, 'close' => 63.17, 'volume' => 1],
            ],
        ]);
        $bars->shouldReceive('fetchRecentBars')->with('HALO', Mockery::any(), Mockery::any())->andReturn([
            'today' => ['open' => 80, 'high' => 80, 'low' => 80, 'close' => 80, 'volume' => 1],
            'adv30' => 1.0,
            'bars' => [
                ['date' => '2026-07-16', 'open' => 80, 'high' => 80, 'low' => 80, 'close' => 80.0, 'volume' => 1],
                ['date' => '2026-08-05', 'open' => 82, 'high' => 82, 'low' => 82, 'close' => 82.0, 'volume' => 1],
            ],
        ]);

        $resolver = Mockery::mock(\App\Services\BenchmarkCloseResolver::class);
        $resolver->shouldReceive('benchmarkTicker')->andReturn('SPY');
        $resolver->shouldReceive('resolveTradingDayClose')->andReturn(750.0);
        $resolver->shouldReceive('closesBetween')->andReturn([
            '2026-07-15' => 751.83,
            '2026-07-16' => 754.81,
            '2026-08-05' => 771.33,
            '2026-08-06' => 769.79,
            '2026-08-10' => 773.26,
        ]);

        $service = new AlphaIbkrRevolutSnapshotBackfill(
            $bars,
            new BankrollSnapshotService($resolver),
            $resolver,
        );

        $result = $service->backfill(
            $user,
            [
                '2026-07-16' => 3437.84,
                '2026-08-05' => 7858.60,
                '2026-08-06' => 7940.86,
                '2026-08-10' => 7866.94,
            ],
            Carbon::parse('2026-07-15'),
            Carbon::parse('2026-08-10'),
            dryRun: false,
            tickers: ['BAC', 'HALO'],
        );

        $byDate = collect($result['days'])->keyBy('date');

        // Jul 16: IBKR + BAC 22*52 + HALO 13*80
        $this->assertEqualsWithDelta(3437.84 + 1144 + 1040, $byDate['2026-07-16']['amount'], 0.01);

        // Aug 5: still in shares
        $this->assertEqualsWithDelta(7858.60 + 22 * 62 + 13 * 82, $byDate['2026-08-05']['amount'], 0.01);

        // Aug 6: HALO sold → cash 13*83.18, not share MTM
        $this->assertEqualsWithDelta(7940.86 + 22 * 63 + 1081.34, $byDate['2026-08-06']['amount'], 0.01);

        // Aug 10: withdrawal day → no HALO cash
        $this->assertEqualsWithDelta(7866.94 + 22 * 63.17, $byDate['2026-08-10']['amount'], 0.01);

        $this->assertDatabaseHas('bankroll_snapshots', [
            'user_id' => $user->id,
            'recorded_on' => '2026-07-16 00:00:00',
        ]);
        $this->assertGreaterThan(0, BankrollSnapshot::query()->where('user_id', $user->id)->count());
    }
}
