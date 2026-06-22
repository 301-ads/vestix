<?php

namespace Tests\Unit;

use App\Support\PolygonRateLimiter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PolygonRateLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('vestix:polygon:last_request_at');
        parent::tearDown();
    }

    public function test_skips_wait_when_delay_is_zero(): void
    {
        config(['vestix.polygon.rate_limit_delay' => 0]);

        $start = microtime(true);
        app(PolygonRateLimiter::class)->waitBeforeRequest();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.05, $elapsed);
        $this->assertNull(Cache::get('vestix:polygon:last_request_at'));
    }

    public function test_records_timestamp_after_request(): void
    {
        config(['vestix.polygon.rate_limit_delay' => 1]);

        $limiter = app(PolygonRateLimiter::class);
        $limiter->waitBeforeRequest();

        $this->assertNotNull(Cache::get('vestix:polygon:last_request_at'));
    }

    public function test_waits_until_minimum_interval_elapses(): void
    {
        config(['vestix.polygon.rate_limit_delay' => 1]);

        $limiter = app(PolygonRateLimiter::class);
        $limiter->waitBeforeRequest();

        $start = microtime(true);
        $limiter->waitBeforeRequest();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.9, $elapsed);
    }
}
