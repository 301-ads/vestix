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
}
