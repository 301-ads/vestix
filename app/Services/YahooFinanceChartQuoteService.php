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

    /**
     * Intraday OHLC series from Yahoo chart API (no paid key).
     *
     * @return list<array{time: int, open: float, high: float, low: float, close: float, volume: float}>|null
     */
    public function fetchIntradayBars(
        string $symbol,
        string $interval = '5m',
        string $range = '1d',
        bool $includePrePost = true,
    ): ?array {
        $symbol = trim($symbol);

        if ($symbol === '') {
            return null;
        }

        $allowedIntervals = ['1m', '2m', '5m', '15m', '30m', '60m', '90m'];
        if (! in_array($interval, $allowedIntervals, true)) {
            $interval = '5m';
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Vestix/1.0)',
                    'Accept' => 'application/json',
                ])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                    'interval' => $interval,
                    'range' => $range,
                    'includePrePost' => $includePrePost ? 'true' : 'false',
                ]);

            if (! $response->successful()) {
                Log::info('Yahoo Finance intraday bars failed.', [
                    'symbol' => $symbol,
                    'interval' => $interval,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $timestamps = $response->json('chart.result.0.timestamp');
            $quote = $response->json('chart.result.0.indicators.quote.0');

            if (! is_array($timestamps) || ! is_array($quote)) {
                return null;
            }

            $opens = is_array($quote['open'] ?? null) ? $quote['open'] : [];
            $highs = is_array($quote['high'] ?? null) ? $quote['high'] : [];
            $lows = is_array($quote['low'] ?? null) ? $quote['low'] : [];
            $closes = is_array($quote['close'] ?? null) ? $quote['close'] : [];
            $volumes = is_array($quote['volume'] ?? null) ? $quote['volume'] : [];

            $bars = [];

            foreach ($timestamps as $index => $timestamp) {
                $close = $closes[$index] ?? null;

                if ($close === null || (float) $close <= 0) {
                    continue;
                }

                $open = (float) ($opens[$index] ?? $close);
                $high = (float) ($highs[$index] ?? max($open, (float) $close));
                $low = (float) ($lows[$index] ?? min($open, (float) $close));

                $bars[] = [
                    'time' => (int) $timestamp,
                    'open' => round($open, 4),
                    'high' => round($high, 4),
                    'low' => round($low, 4),
                    'close' => round((float) $close, 4),
                    'volume' => (float) ($volumes[$index] ?? 0),
                ];
            }

            return $bars === [] ? null : $bars;
        } catch (\Throwable $exception) {
            Log::warning('Yahoo Finance intraday bars exception.', [
                'symbol' => $symbol,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
