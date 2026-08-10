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
        $this->assertTrue($payload['short'] === false);
        $this->assertNotNull($payload['entry_time']);
        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('entry', $payload['markers'][0]['role']);
        $this->assertSame('up', $payload['markers'][0]['direction']);
        $this->assertSame('belowBar', $payload['markers'][0]['position']);
        $this->assertSame('#22c55e', $payload['markers'][0]['color']);
        $this->assertArrayNotHasKey('shape', $payload['markers'][0]);
        $this->assertArrayNotHasKey('text', $payload['markers'][0]);

        $this->assertGreaterThanOrEqual(2, count($payload['markers']));
        $exitMarker = $payload['markers'][1];
        $this->assertSame('exit', $exitMarker['role']);
        $this->assertSame('down', $exitMarker['direction']);
        $this->assertSame('aboveBar', $exitMarker['position']);
        $this->assertSame('#ef4444', $exitMarker['color']);
        $this->assertSame($payload['exit_time'], $exitMarker['time']);
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
        $this->assertSame('2026-06-05', $payload['entry_time']);
        $this->assertSame('2026-06-02', $payload['fog_time']);
        $this->assertNotSame($payload['entry_time'], $payload['fog_time']);
        $entryMarker = $payload['markers'][0];
        $this->assertSame('2026-06-05', $entryMarker['time']);
        $this->assertSame('entry', $entryMarker['role']);
        $this->assertSame('up', $entryMarker['direction']);
        $this->assertSame('belowBar', $entryMarker['position']);
        $this->assertNotSame('2026-06-02', $entryMarker['time']);
    }

    public function test_fog_ends_on_signal_bounce_not_entry_fill(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'BOUNCE',
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
                $bar['open'] = 100.0;
                $bar['high'] = 102.0;
                $bar['low'] = 99.5;
                $bar['close'] = 101.2;
            }

            if ($bar['date'] === '2026-06-05') {
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
        $this->assertSame('2026-06-02', $payload['fog_time']);
        $this->assertSame('2026-06-05', $payload['entry_time']);

        $fogIndex = null;
        $entryIndex = null;
        foreach ($payload['candles'] as $index => $candle) {
            if ($candle['time'] === $payload['fog_time']) {
                $fogIndex = $index;
            }
            if ($candle['time'] === $payload['entry_time']) {
                $entryIndex = $index;
            }
        }

        $this->assertNotNull($fogIndex);
        $this->assertNotNull($entryIndex);
        $this->assertLessThan($entryIndex, $fogIndex);
    }

    public function test_fog_entry_does_not_use_closed_at_when_signal_dates_missing(): void
    {
        Cache::flush();

        // HALO-class: no signal dates, exit today, true fill weeks earlier.
        $position = Position::factory()->create([
            'ticker' => 'HALO',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 76.06,
            'exit_price' => 83.18,
            'initial_sl' => 73.78,
            'quantity' => 26,
            'signal_bar_date' => null,
            'detected_signal_bar_date' => null,
            'entry_setup_captured_at' => null,
            'created_at' => '2026-07-10 14:00:00',
            'closed_at' => '2026-08-06 14:21:00',
        ]);

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 100; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            // Stay below entry until the fill day.
            $bars[] = [
                'open' => 72.0,
                'high' => 74.5,
                'low' => 71.0,
                'close' => 73.5,
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        foreach ($bars as &$bar) {
            if ($bar['date'] === '2026-07-09') {
                $bar['open'] = 74.0;
                $bar['high'] = 75.5;
                $bar['low'] = 73.5;
                $bar['close'] = 75.0;
            }

            if ($bar['date'] === '2026-07-10') {
                // Breakout / fill at entry.
                $bar['open'] = 75.2;
                $bar['high'] = 77.0;
                $bar['low'] = 74.8;
                $bar['close'] = 76.5;
            }

            if ($bar['date'] >= '2026-07-11') {
                $bar['open'] = 78.0;
                $bar['high'] = 84.0;
                $bar['low'] = 77.0;
                $bar['close'] = 83.0;
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
        $this->assertSame('2026-07-10', $payload['entry_time']);
        $this->assertNotSame('2026-08-06', $payload['entry_time']);
        $this->assertSame('2026-08-06', $payload['exit_time']);
        // Without a signal date, fog stops on the candle before the fill.
        $this->assertSame('2026-07-09', $payload['fog_time']);
        $this->assertTrue($payload['fog_time'] < $payload['entry_time']);

        $fogIndex = null;
        foreach ($payload['candles'] as $index => $candle) {
            if ($candle['time'] === $payload['fog_time']) {
                $fogIndex = $index;
                break;
            }
        }

        $this->assertNotNull($fogIndex);
        // Fog candle at the pre-entry bounce should not yet be the extended ~83 exit-day close.
        $this->assertLessThan(80.0, (float) $payload['candles'][$fogIndex]['close']);
    }

    public function test_exit_time_waits_for_closed_session_bar(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'AWK',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 134.99,
            'exit_price' => 132.28,
            'initial_sl' => 129.37,
            'quantity' => 7,
            'signal_bar_date' => '2026-07-28',
            'closed_at' => '2026-08-10 15:00:00',
        ]);

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 100; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            if ($date > '2026-08-07') {
                break;
            }

            $bars[] = [
                'open' => 130.0,
                'high' => 133.0,
                'low' => 128.0,
                'close' => 131.0,
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        foreach ($bars as &$bar) {
            if ($bar['date'] === '2026-07-28') {
                $bar['high'] = 133.0;
                $bar['close'] = 132.0;
            }
            if ($bar['date'] === '2026-07-29') {
                $bar['high'] = 136.0;
                $bar['low'] = 133.0;
                $bar['close'] = 135.0;
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
        $this->assertSame('2026-07-28', $payload['fog_time']);
        $this->assertNull($payload['exit_time']);
        $this->assertFalse(collect($payload['markers'])->contains(fn (array $m): bool => $m['role'] === 'exit'));
    }

    public function test_sma20_covers_first_visible_candle_with_warmup_history(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'SMA',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 100.0,
            'exit_price' => 110.0,
            'initial_sl' => 95.0,
            'quantity' => 1,
            'signal_bar_date' => '2026-06-15',
            'closed_at' => '2026-06-20 15:00:00',
        ]);

        $bars = [];
        $start = Carbon::parse('2025-11-01');

        for ($i = 0; $i < 220; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $bars[] = [
                'open' => 90.0 + ($i * 0.01),
                'high' => 91.0 + ($i * 0.01),
                'low' => 89.0 + ($i * 0.01),
                'close' => 90.5 + ($i * 0.01),
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        foreach ($bars as &$bar) {
            if ($bar['date'] === '2026-06-15') {
                $bar['high'] = 99.0;
                $bar['close'] = 98.0;
            }
            if ($bar['date'] === '2026-06-16') {
                $bar['high'] = 101.5;
                $bar['low'] = 99.0;
                $bar['close'] = 100.5;
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
        $this->assertGreaterThanOrEqual(100, count($payload['candles']));
        $this->assertNotEmpty($payload['sma20']);
        $this->assertSame($payload['candles'][0]['time'], $payload['sma20'][0]['time']);
        $this->assertSame($payload['candles'][0]['time'], $payload['rsi14'][0]['time']);
    }
}
