<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Light Yahoo Finance chart client for EU ETF last prices (e.g. VWCE.DE / Xetra).
 * Used when Finnhub/Polygon free tiers lack European quotes and Alpha Vantage lags one session.
 */
class YahooFinanceChartQuoteService
{
    public function fetchLivePrice(string $symbol): ?float
    {
        $symbol = trim($symbol);

        if ($symbol === '') {
            return null;
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Vestix/1.0)',
                    'Accept' => 'application/json',
                ])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                    'interval' => '1d',
                    'range' => '5d',
                ]);

            if (! $response->successful()) {
                Log::info('Yahoo Finance chart quote failed.', [
                    'symbol' => $symbol,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $meta = $response->json('chart.result.0.meta');

            if (! is_array($meta)) {
                return null;
            }

            $price = (float) ($meta['regularMarketPrice'] ?? 0);

            if ($price <= 0) {
                $price = (float) ($meta['previousClose'] ?? $meta['chartPreviousClose'] ?? 0);
            }

            return $price > 0 ? round($price, 4) : null;
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance chart quote exception.', [
                'symbol' => $symbol,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
