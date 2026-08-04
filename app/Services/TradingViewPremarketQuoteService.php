<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free TradingView america/scan client for real extended-hours prints.
 *
 * Returns premarket_close when TradingView has meaningful pre-market activity;
 * null price when the listing exists but there are no (or only ghost) EH trades
 * — matching the chart UI "Pre-market No trades".
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
                    'columns' => [
                        'name',
                        'close',
                        'premarket_close',
                        'premarket_open',
                        'premarket_high',
                        'premarket_low',
                        'premarket_volume',
                        'exchange',
                        'type',
                    ],
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

            if ($this->isGhostPremarketPrint($match)) {
                Log::info('TradingView premarket rejected as ghost print (no real EH trades).', [
                    'ticker' => $ticker,
                    'price' => $premarket,
                    'volume' => $match['premarket_volume'],
                    'open' => $match['premarket_open'],
                    'high' => $match['premarket_high'],
                    'low' => $match['premarket_low'],
                ]);

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
     * @param  array{
     *     premarket_close: float|null,
     *     premarket_open: float|null,
     *     premarket_high: float|null,
     *     premarket_low: float|null,
     *     premarket_volume: float|null,
     *     close: float|null,
     *     exchange: string
     * }  $match
     */
    private function isGhostPremarketPrint(array $match): bool
    {
        $volume = $match['premarket_volume'];

        if ($volume === null || $volume <= 0) {
            return true;
        }

        $open = $match['premarket_open'];
        $high = $match['premarket_high'];
        $low = $match['premarket_low'];
        $close = $match['premarket_close'];

        // Scanner often leaves a leftover single-lot print (O=H=L=C, tiny volume)
        // while the chart UI shows "Pre-market No trades" (e.g. WH @ 140 shares).
        $isFlat = $open !== null
            && $high !== null
            && $low !== null
            && $close !== null
            && round($open, 4) === round($high, 4)
            && round($high, 4) === round($low, 4)
            && round($low, 4) === round($close, 4);

        if (! $isFlat) {
            return false;
        }

        $minFlatVolume = max(1, (int) config('vestix.premarket.min_flat_print_volume', 1000));

        return $volume < $minFlatVolume;
    }

    /**
     * @param  list<array{s?: string, d?: list<mixed>}>  $rows
     * @return array{
     *     premarket_close: float|null,
     *     premarket_open: float|null,
     *     premarket_high: float|null,
     *     premarket_low: float|null,
     *     premarket_volume: float|null,
     *     close: float|null,
     *     exchange: string
     * }|null
     */
    private function pickUsListing(array $rows, string $ticker): ?array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $values = $row['d'] ?? null;

            if (! is_array($values) || count($values) < 9) {
                continue;
            }

            $name = strtoupper(trim((string) ($values[0] ?? '')));
            $exchange = strtoupper(trim((string) ($values[7] ?? '')));
            $type = strtolower(trim((string) ($values[8] ?? '')));

            if ($name !== $ticker || $type !== 'stock') {
                continue;
            }

            $candidates[] = [
                'close' => $this->numericOrNull($values[1] ?? null),
                'premarket_close' => $this->numericOrNull($values[2] ?? null),
                'premarket_open' => $this->numericOrNull($values[3] ?? null),
                'premarket_high' => $this->numericOrNull($values[4] ?? null),
                'premarket_low' => $this->numericOrNull($values[5] ?? null),
                'premarket_volume' => $this->numericOrNull($values[6] ?? null),
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
            'premarket_open' => $best['premarket_open'],
            'premarket_high' => $best['premarket_high'],
            'premarket_low' => $best['premarket_low'],
            'premarket_volume' => $best['premarket_volume'],
            'close' => $best['close'],
            'exchange' => $best['exchange'],
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
