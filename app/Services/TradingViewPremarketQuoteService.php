<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free TradingView america/scan client for real extended-hours prints.
 *
 * Returns premarket_close when TradingView has pre-market activity; null price when
 * the listing exists but there are no extended-hours trades (e.g. GNTX).
 */
class TradingViewPremarketQuoteService
{
    /** @var list<string> */
    private const US_EXCHANGES = ['NYSE', 'NASDAQ', 'AMEX', 'NYSEARCA', 'BATS', 'OTC'];

    /**
     * @return array{ok: bool, price: float|null}
     */
    public function fetchPremarketQuote(string $ticker): array
    {
        $ticker = strtoupper(trim($ticker));

        if ($ticker === '') {
            return ['ok' => false, 'price' => null];
        }

        $baseUrl = rtrim((string) config('vestix.tradingview.scanner_url'), '/');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; Vestix/1.0)',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Origin' => 'https://www.tradingview.com',
                ])
                ->post($baseUrl, [
                    'filter' => [
                        ['left' => 'name', 'operation' => 'equal', 'right' => $ticker],
                        ['left' => 'type', 'operation' => 'equal', 'right' => 'stock'],
                    ],
                    'columns' => ['name', 'close', 'premarket_close', 'premarket_volume', 'exchange', 'type'],
                    'range' => [0, 10],
                ]);

            if (! $response->successful()) {
                Log::info('TradingView premarket scanner failed.', [
                    'ticker' => $ticker,
                    'status' => $response->status(),
                ]);

                return ['ok' => false, 'price' => null];
            }

            $rows = $response->json('data');

            if (! is_array($rows) || $rows === []) {
                return ['ok' => false, 'price' => null];
            }

            $match = $this->pickUsListing($rows, $ticker);

            if ($match === null) {
                return ['ok' => false, 'price' => null];
            }

            $premarket = $match['premarket_close'];

            if ($premarket === null || $premarket <= 0) {
                // Listing found; TradingView reports no pre-market print.
                return ['ok' => true, 'price' => null];
            }

            return ['ok' => true, 'price' => round($premarket, 4)];
        } catch (\Throwable $exception) {
            Log::warning('TradingView premarket scanner exception.', [
                'ticker' => $ticker,
                'message' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'price' => null];
        }
    }

    public function fetchPremarketPrice(string $ticker): ?float
    {
        $quote = $this->fetchPremarketQuote($ticker);

        return $quote['ok'] ? $quote['price'] : null;
    }

    /**
     * @param  list<array{s?: string, d?: list<mixed>}>  $rows
     * @return array{premarket_close: float|null, close: float|null, exchange: string}|null
     */
    private function pickUsListing(array $rows, string $ticker): ?array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $values = $row['d'] ?? null;

            if (! is_array($values) || count($values) < 6) {
                continue;
            }

            $name = strtoupper(trim((string) ($values[0] ?? '')));
            $exchange = strtoupper(trim((string) ($values[4] ?? '')));
            $type = strtolower(trim((string) ($values[5] ?? '')));

            if ($name !== $ticker || $type !== 'stock') {
                continue;
            }

            $premarketRaw = $values[2] ?? null;
            $closeRaw = $values[1] ?? null;

            $candidates[] = [
                'premarket_close' => is_numeric($premarketRaw) ? (float) $premarketRaw : null,
                'close' => is_numeric($closeRaw) ? (float) $closeRaw : null,
                'exchange' => $exchange,
                'us' => in_array($exchange, self::US_EXCHANGES, true),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            return ((int) $right['us']) <=> ((int) $left['us']);
        });

        $best = $candidates[0];

        return [
            'premarket_close' => $best['premarket_close'],
            'close' => $best['close'],
            'exchange' => $best['exchange'],
        ];
    }
}
