<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Light Yahoo Finance chart client for EU ETF last prices (e.g. VWCE.DE / Xetra)
 * and US extended-hours last prints when Finnhub/Polygon lag pre/post market.
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

    /**
     * Last pre/post-market print from 1m bars (includePrePost), falling back to meta fields.
     */
    public function fetchExtendedHoursLastPrice(string $symbol): ?float
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
                    'interval' => '1m',
                    'range' => '1d',
                    'includePrePost' => 'true',
                ]);

            if (! $response->successful()) {
                Log::info('Yahoo Finance extended-hours quote failed.', [
                    'symbol' => $symbol,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $meta = $response->json('chart.result.0.meta');
            $closes = $response->json('chart.result.0.indicators.quote.0.close');

            if (is_array($closes)) {
                for ($i = count($closes) - 1; $i >= 0; $i--) {
                    $close = $closes[$i];

                    if ($close !== null && (float) $close > 0) {
                        return round((float) $close, 4);
                    }
                }
            }

            if (! is_array($meta)) {
                return null;
            }

            foreach (['preMarketPrice', 'postMarketPrice', 'regularMarketPrice'] as $field) {
                $price = (float) ($meta[$field] ?? 0);

                if ($price > 0) {
                    return round($price, 4);
                }
            }

            return null;
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance extended-hours quote exception.', [
                'symbol' => $symbol,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
