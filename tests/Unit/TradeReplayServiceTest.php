<?php

namespace Tests\Unit;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Services\TradeReplayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class TradeReplayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_demo_fallback_includes_sma_rsi_and_entry_marker(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'ALL',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 245.41,
            'exit_price' => 262.00,
            'initial_sl' => 235.00,
            'current_sl' => 235.00,
            'quantity' => 3,
            'signal_bar_date' => now()->subWeeks(3)->toDateString(),
            'closed_at' => now()->subWeek(),
        ]);

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn(null);

        $payload = (new TradeReplayService($dailyBars))->build($position, allowDemoFallback: true);

        $this->assertNotNull($payload);
        $this->assertTrue($payload['demo']);
        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('arrowUp', $payload['markers'][0]['shape']);
        $this->assertSame('belowBar', $payload['markers'][0]['position']);
        $this->assertSame(0.75, $payload['markers'][0]['size']);
        $this->assertArrayNotHasKey('text', $payload['markers'][0]);
    }

    public function test_long_entry_marker_skips_signal_bar_when_buy_stop_above_signal_high(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'TEST',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 105.0,
            'exit_price' => 112.0,
            'initial_sl' => 98.0,
            'quantity' => 1,
            'signal_bar_date' => '2026-06-02',
            'closed_at' => '2026-06-10 15:00:00',
        ]);

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 50; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $bars[] = [
                'open' => 100.0 + ($i * 0.02),
                'high' => 101.0 + ($i * 0.02),
                'low' => 99.0 + ($i * 0.02),
                'close' => 100.5 + ($i * 0.02),
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        foreach ($bars as &$bar) {
            if ($bar['date'] === '2026-06-02') {
                // Signal bounce: high stays below buy-stop 105.
                $bar['open'] = 100.0;
                $bar['high'] = 102.0;
                $bar['low'] = 99.5;
                $bar['close'] = 101.2;
            }

            if ($bar['date'] === '2026-06-05') {
                // First day that reaches the buy-stop.
                $bar['open'] = 104.2;
                $bar['high'] = 106.5;
                $bar['low'] = 103.8;
                $bar['close'] = 105.8;
            }
        }
        unset($bar);

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);

        $payload = (new TradeReplayService($dailyBars))->build($position, allowDemoFallback: false);

        $this->assertNotNull($payload);
        $entryMarker = $payload['markers'][0];
        $this->assertSame('2026-06-05', $entryMarker['time']);
        $this->assertSame('arrowUp', $entryMarker['shape']);
        $this->assertSame('belowBar', $entryMarker['position']);
        $this->assertNotSame('2026-06-02', $entryMarker['time']);
    }
}
