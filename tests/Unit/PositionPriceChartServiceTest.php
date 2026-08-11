<?php

namespace Tests\Unit;

use App\Contracts\DailyBarProvider;
use App\Enums\PremarketScanResult;
use App\Models\Position;
use App\Models\SniperDailyBar;
use App\Services\PositionPriceChartService;
use App\Services\YahooFinanceChartQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
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
        $service = $this->makeService(
            Mockery::mock(DailyBarProvider::class),
            Mockery::mock(YahooFinanceChartQuoteService::class),
        );

        $this->assertSame('1Y', $service->normalizeRange('1J'));
        $this->assertSame('3M', $service->normalizeRange('bogus'));
        $this->assertSame('1W', $service->normalizeRange('1w'));
        $this->assertSame('1D', $service->normalizeRange('1d'));
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

        $payload = $this->makeService($dailyBars)->build($position, '1W');

        $this->assertNotNull($payload);
        $this->assertSame('1W', $payload['range']);
        $this->assertFalse($payload['intraday']);
        $this->assertCount(5, $payload['points']);
        $this->assertFalse($payload['demo']);

        $first = $payload['points'][0]['value'];
        $last = $payload['points'][4]['value'];
        $this->assertSame(round($last - $first, 4), $payload['period_change']['absolute']);
        $this->assertTrue($payload['period_change']['positive']);
        $this->assertSame(108.0, $payload['levels']['stop']);
        $this->assertSame(110.0, $payload['levels']['entry']);
        $this->assertNotNull($payload['levels']['target1']);
        $this->assertSame('area', $payload['series']);
        $this->assertArrayNotHasKey('candles', $payload);
        $this->assertArrayNotHasKey('premarket', $payload);
    }

    public function test_scout_payload_uses_candles_signal_levels_and_premarket_meta(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00', 'America/New_York'));

        $bars = [];
        $start = Carbon::parse('2026-05-01');

        for ($i = 0; $i < 80; $i++) {
            $close = 50 + ($i * 0.1);
            $bars[] = [
                'open' => $close - 0.2,
                'high' => $close + 0.4,
                'low' => $close - 0.4,
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

        $position = Position::factory()->scout()->create([
            'ticker' => 'SCOUT',
            'direction' => 'long',
            'entry_price' => 55.0,
            'signal_high' => 54.5,
            'signal_low' => 52.0,
            'latest_atr_14' => 1.0,
            'latest_sma_20' => 53.25,
            'signal_bar_date' => '2026-07-15',
            'premarket_price' => 56.10,
            'premarket_scan_type' => PremarketScanResult::GapRisk,
            'premarket_reference_price' => 54.5,
            'premarket_distance_pct' => 2.9358,
            'premarket_checked_at' => now(),
        ]);

        $payload = $this->makeService($dailyBars)->build($position, '3M');

        $this->assertNotNull($payload);
        $this->assertSame('candles', $payload['series']);
        $this->assertArrayHasKey('candles', $payload);
        $this->assertGreaterThanOrEqual(2, count($payload['candles']));
        $this->assertArrayHasKey('open', $payload['candles'][0]);
        $this->assertArrayHasKey('high', $payload['candles'][0]);
        $this->assertArrayHasKey('low', $payload['candles'][0]);
        $this->assertArrayHasKey('close', $payload['candles'][0]);

        $this->assertSame(55.0, $payload['levels']['entry']);
        $this->assertSame(54.5, $payload['levels']['signal_high']);
        $this->assertSame(52.0, $payload['levels']['signal_low']);
        $this->assertSame(53.25, $payload['levels']['sma20']);
        $this->assertNotNull($payload['levels']['stop']);
        $this->assertSame((float) $position->new_sl, $payload['levels']['stop']);
        $this->assertNotNull($payload['levels']['target1']);

        $this->assertNotEmpty($payload['markers']);
        $this->assertSame('signal', $payload['markers'][0]['role']);
        $this->assertSame('2026-07-15', $payload['markers'][0]['time']);

        $this->assertSame(56.1, $payload['premarket']['price']);
        $this->assertTrue($payload['premarket']['checked']);
        $this->assertSame('danger', $payload['premarket']['tone']);
        $this->assertNotNull($payload['premarket']['description']);

        Carbon::setTestNow();
    }

    public function test_scout_premarket_meta_when_not_checked_today(): void
    {
        Cache::flush();

        $dailyBars = Mockery::mock(DailyBarProvider::class);
        $dailyBars->shouldReceive('fetchRecentBars')->andReturn(null);

        $position = Position::factory()->scout()->create([
            'ticker' => 'PMNONE',
            'entry_price' => 40.0,
            'signal_high' => 39.5,
            'signal_low' => 38.0,
            'latest_atr_14' => 0.8,
            'premarket_checked_at' => null,
        ]);

        $payload = $this->makeService($dailyBars)->build($position, '1M');

        $this->assertNotNull($payload);
        $this->assertSame('candles', $payload['series']);
        $this->assertFalse($payload['premarket']['checked']);
        $this->assertSame('Nog geen pre-market check vandaag.', $payload['premarket']['description']);
        $this->assertSame('gray', $payload['premarket']['tone']);
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

        $payload = $this->makeService($dailyBars)->build($position, '3M');

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

        $payload = $this->makeService($dailyBars)->build($position, '1M');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['demo']);
        $this->assertCount(22, $payload['points']);
        $this->assertSame('EMPTY', $payload['ticker']);
    }

    public function test_one_day_range_uses_yahoo_intraday_bars(): void
    {
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-11 15:00:00', 'America/New_York'));

        $yahooBars = [];
        $start = Carbon::parse('2026-08-11 09:30:00', 'America/New_York');

        for ($i = 0; $i < 12; $i++) {
            $close = 24.0 + ($i * 0.1);
            $yahooBars[] = [
                'time' => $start->copy()->addMinutes($i * 5)->timestamp,
                'open' => $close - 0.05,
                'high' => $close + 0.05,
                'low' => $close - 0.08,
                'close' => $close,
                'volume' => 10_000,
            ];
        }

        $yahoo = Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchIntradayBars')
            ->once()
            ->with('PINS', '5m', '1d', true)
            ->andReturn($yahooBars);

        $position = Position::factory()->create([
            'ticker' => 'PINS',
            'status' => 'open',
            'entry_price' => 23.79,
            'current_sl' => 23.12,
            'quantity' => 59,
            'signal_bar_date' => '2026-07-01',
            'entry_setup_captured_at' => '2026-07-01 16:00:00',
            'created_at' => '2026-07-01 16:00:00',
        ]);

        $payload = $this->makeService(
            Mockery::mock(DailyBarProvider::class),
            $yahoo,
        )->build($position, '1D');

        $this->assertNotNull($payload);
        $this->assertSame('1D', $payload['range']);
        $this->assertTrue($payload['intraday']);
        $this->assertFalse($payload['demo']);
        $this->assertCount(12, $payload['points']);
        $this->assertIsInt($payload['points'][0]['time']);
        $this->assertSame(23.79, $payload['levels']['entry']);
        $this->assertEmpty($payload['markers']);

        Carbon::setTestNow();
    }

    public function test_one_day_falls_back_to_demo_when_yahoo_empty(): void
    {
        Cache::flush();

        $yahoo = Mockery::mock(YahooFinanceChartQuoteService::class);
        $yahoo->shouldReceive('fetchIntradayBars')->andReturn(null);

        $position = Position::factory()->create([
            'ticker' => 'FAIL',
            'status' => 'open',
            'entry_price' => 10.0,
            'current_sl' => 9.0,
            'quantity' => 1,
        ]);

        $payload = $this->makeService(
            Mockery::mock(DailyBarProvider::class),
            $yahoo,
        )->build($position, '1D');

        $this->assertNotNull($payload);
        $this->assertTrue($payload['intraday']);
        $this->assertTrue($payload['demo']);
        $this->assertGreaterThanOrEqual(2, count($payload['points']));
    }

    /**
     * @param  MockInterface&DailyBarProvider  $dailyBars
     * @param  (MockInterface&YahooFinanceChartQuoteService)|null  $yahoo
     */
    private function makeService(
        DailyBarProvider $dailyBars,
        ?YahooFinanceChartQuoteService $yahoo = null,
    ): PositionPriceChartService {
        $yahoo ??= Mockery::mock(YahooFinanceChartQuoteService::class);

        return new PositionPriceChartService($dailyBars, $yahoo);
    }
}
