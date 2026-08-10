<?php

namespace Tests\Unit;

use App\Contracts\DailyBarProvider;
use App\Services\BenchmarkCloseResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class BenchmarkCloseResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        Cache::flush();

        parent::tearDown();
    }

    public function test_before_us_close_uses_previous_completed_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 08:00:00', 'Europe/Amsterdam'));

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')->once()->andReturn([
            'today' => ['open' => 1, 'high' => 1, 'low' => 1, 'close' => 757.67, 'volume' => 1],
            'adv30' => 1.0,
            'bars' => [
                ['date' => '2026-07-31', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 747.03, 'volume' => 1],
                ['date' => '2026-08-03', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 757.67, 'volume' => 1],
            ],
        ]);

        $resolver = new BenchmarkCloseResolver($provider);

        $close = $resolver->resolveTradingDayClose(
            Carbon::parse('2026-08-04', 'Europe/Amsterdam')->startOfDay(),
        );

        $this->assertSame(757.67, $close);
    }

    public function test_stale_fallback_close_is_not_cached_for_a_full_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 08:00:00', 'Europe/Amsterdam'));

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')->twice()->andReturn(
            [
                'today' => ['open' => 1, 'high' => 1, 'low' => 1, 'close' => 747.03, 'volume' => 1],
                'adv30' => 1.0,
                'bars' => [
                    ['date' => '2026-07-31', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 747.03, 'volume' => 1],
                ],
            ],
            [
                'today' => ['open' => 1, 'high' => 1, 'low' => 1, 'close' => 757.67, 'volume' => 1],
                'adv30' => 1.0,
                'bars' => [
                    ['date' => '2026-07-31', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 747.03, 'volume' => 1],
                    ['date' => '2026-08-03', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 757.67, 'volume' => 1],
                ],
            ],
        );

        $resolver = new BenchmarkCloseResolver($provider);
        $date = Carbon::parse('2026-08-03', 'America/New_York')->startOfDay();

        $this->assertSame(747.03, $resolver->resolveCloseForDate($date));

        Carbon::setTestNow(Carbon::parse('2026-08-03 18:00:00', 'America/New_York'));
        $this->travel(31)->minutes();

        $this->assertSame(757.67, $resolver->resolveCloseForDate($date));
    }

    public function test_closes_between_caches_range_and_skips_repeat_fetch(): void
    {
        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldReceive('fetchRecentBars')->once()->andReturn([
            'today' => ['open' => 1, 'high' => 1, 'low' => 1, 'close' => 520, 'volume' => 1],
            'adv30' => 1.0,
            'bars' => [
                ['date' => '2026-01-05', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 490, 'volume' => 1],
                ['date' => '2026-01-06', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 500, 'volume' => 1],
            ],
        ]);

        $resolver = new BenchmarkCloseResolver($provider);
        $from = Carbon::parse('2026-01-05 12:00:00', 'America/New_York');
        $to = Carbon::parse('2026-01-06 12:00:00', 'America/New_York');

        $first = $resolver->closesBetween($from, $to);
        $second = $resolver->closesBetween($from, $to);

        $this->assertSame([
            '2026-01-05' => 490.0,
            '2026-01-06' => 500.0,
        ], $first);
        $this->assertSame($first, $second);
    }

    public function test_closes_between_uses_daily_cache_without_remote_after_miss(): void
    {
        Cache::put('vestix:benchmark-close:SPY:2026-01-05', 490.0, now()->addDay());
        Cache::put('vestix:benchmark-closes:SPY:2026-01-05:2026-01-06:miss', true, now()->addMinutes(10));

        $provider = Mockery::mock(DailyBarProvider::class);
        $provider->shouldNotReceive('fetchRecentBars');

        $resolver = new BenchmarkCloseResolver($provider);
        $closes = $resolver->closesBetween(
            Carbon::parse('2026-01-05 12:00:00', 'America/New_York'),
            Carbon::parse('2026-01-06 12:00:00', 'America/New_York'),
        );

        $this->assertSame(['2026-01-05' => 490.0], $closes);
    }
}
