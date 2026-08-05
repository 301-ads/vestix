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
        $this->assertGreaterThan(30, count($payload['candles']));
        $this->assertNotEmpty($payload['sma20']);
        $this->assertNotEmpty($payload['rsi14']);
        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('Entry', $payload['markers'][0]['text']);
        $this->assertContains($payload['markers'][0]['time'], array_column($payload['candles'], 'time'));
        $this->assertSame(245.41, $payload['markers'][0]['price']);
        $this->assertSame('atPriceBottom', $payload['markers'][0]['position']);
    }

    public function test_entry_marker_uses_bar_that_contains_fill_price(): void
    {
        Cache::flush();

        $position = Position::factory()->create([
            'ticker' => 'TEST',
            'status' => 'closed',
            'direction' => 'long',
            'entry_price' => 100.0,
            'exit_price' => 110.0,
            'initial_sl' => 95.0,
            'quantity' => 1,
            'signal_bar_date' => '2026-06-02',
            'closed_at' => '2026-06-10 15:00:00',
        ]);

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 50; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $isSignal = $date === '2026-06-02';
            $isFill = $date === '2026-06-05';
            $bars[] = [
                'open' => $isFill ? 99.0 : ($isSignal ? 118.0 : 115.0 + ($i * 0.05)),
                'high' => $isFill ? 101.5 : ($isSignal ? 122.0 : 116.0 + ($i * 0.05)),
                'low' => $isFill ? 98.0 : ($isSignal ? 117.0 : 114.0 + ($i * 0.05)),
                'close' => $isFill ? 100.2 : ($isSignal ? 120.0 : 115.5 + ($i * 0.05)),
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);

        $payload = (new TradeReplayService($dailyBars))->build($position, allowDemoFallback: false);

        $this->assertNotNull($payload);
        $this->assertFalse($payload['demo']);
        $entryMarker = collect($payload['markers'])->firstWhere('text', 'Entry');
        $this->assertNotNull($entryMarker);
        $this->assertSame('2026-06-05', $entryMarker['time']);
        $this->assertSame(100.0, $entryMarker['price']);
        $this->assertSame('atPriceBottom', $entryMarker['position']);
    }
}
