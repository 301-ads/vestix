<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SessionVolumeResolver
{
    public function __construct(
        private FinnhubService $finnhub,
        private PolygonDailyBarService $polygon,
        private AlphaVantageDailyBarService $alphaVantage,
    ) {}

    public function resolve(string $ticker, string $sessionDate): ?float
    {
        $providers = [
            'finnhub' => $this->finnhub,
            'polygon' => $this->polygon,
            'alpha_vantage' => $this->alphaVantage,
        ];

        foreach ($providers as $name => $provider) {
            $volume = $this->volumeForDate($provider, $ticker, $sessionDate);

            if ($volume !== null) {
                Log::info('Session volume resolved.', [
                    'ticker' => $ticker,
                    'session_date' => $sessionDate,
                    'provider' => $name,
                    'volume' => $volume,
                ]);

                return $volume;
            }
        }

        return null;
    }

    /**
     * @param  FinnhubService|PolygonDailyBarService|AlphaVantageDailyBarService  $provider
     */
    private function volumeForDate(object $provider, string $ticker, string $sessionDate): ?float
    {
        $bars = $provider->fetchRecentBars($ticker, lookbackDays: 10, limit: 15);

        if ($bars === null) {
            return null;
        }

        foreach ($bars['bars'] as $bar) {
            if ($bar['date'] === $sessionDate && (float) $bar['volume'] > 0) {
                return (float) $bar['volume'];
            }
        }

        return null;
    }
}
