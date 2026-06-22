<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class PolygonRateLimiter
{
    private const LAST_REQUEST_KEY = 'vestix:polygon:last_request_at';

    public function waitBeforeRequest(): void
    {
        $delaySeconds = (int) config('vestix.polygon.rate_limit_delay', 13);

        if ($delaySeconds <= 0) {
            return;
        }

        $now = microtime(true);
        $lastRequest = Cache::get(self::LAST_REQUEST_KEY);

        if ($lastRequest !== null) {
            $elapsed = $now - (float) $lastRequest;
            $waitSeconds = $delaySeconds - $elapsed;

            if ($waitSeconds > 0) {
                usleep((int) round($waitSeconds * 1_000_000));
            }
        }

        $this->markRequestSent();
    }

    public function waitAfterRateLimitResponse(?int $seconds = null): void
    {
        $seconds ??= (int) config('vestix.polygon.rate_limit_delay', 13);

        if ($seconds <= 0) {
            return;
        }

        sleep($seconds);
        $this->markRequestSent();
    }

    private function markRequestSent(): void
    {
        Cache::put(self::LAST_REQUEST_KEY, microtime(true), now()->addMinutes(10));
    }
}
