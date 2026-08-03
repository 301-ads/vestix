<?php

namespace App\Support;

final class FilamentPolling
{
    public const LIVE_INTERVAL = '10s';

    public const IDLE_INTERVAL = '60s';

    /**
     * @deprecated Use {@see interval()} for market-aware polling.
     */
    public const INTERVAL = self::LIVE_INTERVAL;

    /**
     * Live during US premarket + RTH (intraday watch window); quieter after hours.
     */
    public static function interval(): string
    {
        return UsMarketSession::isIntradayTargetWatchWindow()
            ? self::LIVE_INTERVAL
            : self::IDLE_INTERVAL;
    }
}
