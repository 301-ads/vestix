<?php

namespace Tests\Unit;

use App\Contracts\DailyBarProvider;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Services\PositionPriceChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PositionPriceChartServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalize_range_maps_dutch_year_label(): void
    {
        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $service = new PositionPriceChartService($dailyBars);

        $this->assertSame('1Y', $service->normalizeRange('1J'));
        $this->assertSame('3M', $service->normalizeRange('bogus'));
        $this->assertSame('1W', $service->normalizeRange('1w'));
    }

    public function test_build_slices_range_and_computes_period_change(): void
    {
        Cache::flush();

        $bars = [];
        $start = Carbon::parse('2026-01-02');

        for ($i = 0; $i < 40; $i++) {
            $close = 100 + $i;
            $bars[] = [
                'open' => $close - 0.5,
                'high' => $close + 1,
                'low' => $close - 1,
                'close' => $close,
                'volume' => 1_000_000,
                'date' => $start->copy()->addWeekdays($i)->toDateString(),
            ];
        }

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn([
            'today' => $bars[array_key_last($bars)],
            'adv30' => 1_000_000.0,
            'bars' => $bars,
        ]);

        $position = Position::factory()->create([
            'ticker' => 'BAC',
            'status' => 'open',
            'direction' => 'long',
            'entry_price' => 110.0,
            'initial_sl' => 105.0,
            'current_sl' => 108.0,
            'quantity' => 10,
            'signal_bar_date' => '2026-01-10',
            'created_at' => '2026-01-12 15:00:00',
        ]);

        $payload = (new PositionPriceChartService($dailyBars))->build($position, '1W');

        $this->assertNotNull($payload);
        $this->assertSame('1W', $payload['range']);
        $this->assertCount(5, $payload['points']);
        $this->assertFalse($payload['demo']);

        $first = $payload['points'][0]['value'];
        $last = $payload['points'][4]['value'];
        $this->assertSame(round($last - $first, 4), $payload['period_change']['absolute']);
        $this->assertTrue($payload['period_change']['positive']);
        $this->assertSame(108.0, $payload['levels']['stop']);
        $this->assertSame(110.0, $payload['levels']['entry']);
        $this->assertNotNull($payload['levels']['target1']);
    }

    public function test_entry_marker_included_when_fill_day_in_window(): void
    {
        Cache::flush();

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 50; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $bars[] = [
                'open' => 100.0,
                'high' => 101.0,
                'low' => 99.0,
                'close' => 100.5,
                'volume' => 1_000_000,
                'date' => $date,
            ];
        }

        foreach ($bars as &$bar) {
            if ($bar['date'] === '2026-06-02') {
                $bar['high'] = 102.0;
                $bar['close'] = 101.0;
            }

            if ($bar['date'] === '2026-06-05') {
                $bar['high'] = 106.5;
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

        $position = Position::factory()->create([
            'ticker' => 'TEST',
            'status' => 'open',
            'direction' => 'long',
            'entry_price' => 105.0,
            'initial_sl' => 98.0,
            'current_sl' => 98.0,
            'quantity' => 1,
            'signal_bar_date' => '2026-06-02',
            'created_at' => '2026-06-05 16:00:00',
        ]);

        $payload = (new PositionPriceChartService($dailyBars))->build($position, '3M');

        $this->assertNotNull($payload);
        $this->assertSame('2026-06-05', $payload['entry_time']);
        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('entry', $payload['markers'][0]['role']);
        $this->assertSame('2026-06-05', $payload['markers'][0]['time']);
        $this->assertSame(105.0, $payload['markers'][0]['value']);
        $this->assertSame('#22c55e', $payload['markers'][0]['color']);
    }

    public function test_demo_fallback_when_no_market_data(): void
    {
        Cache::flush();

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn(null);

        $position = Position::factory()->create([
            'ticker' => 'EMPTY',
            'status' => 'open',
            'entry_price' => 50.0,
            'current_sl' => 47.0,
            'quantity' => 2,
        ]);

        $this->assertSame(0, SniperDailyBar::query()->where('ticker', 'EMPTY')->count());

        $payload = (new PositionPriceChartService($dailyBars))->build($position, '1M');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['demo']);
        $this->assertCount(22, $payload['points']);
        $this->assertSame('EMPTY', $payload['ticker']);
    }
}
