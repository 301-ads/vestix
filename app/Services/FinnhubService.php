<?php

namespace App\Services;

use App\Enums\EarningsReleaseHour;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FinnhubService
{
    /**
     * @return array{close: float, high: float|null, low: float|null}|null
     */
    public function fetchQuote(string $ticker): ?array
    {
        $data = $this->request('/quote', [
            'symbol' => $ticker,
        ]);

        if ($data === null || ! isset($data['c']) || (float) $data['c'] <= 0) {
            return null;
        }

        return [
            'close' => (float) $data['c'],
            'high' => isset($data['h']) && (float) $data['h'] > 0 ? (float) $data['h'] : null,
            'low' => isset($data['l']) && (float) $data['l'] > 0 ? (float) $data['l'] : null,
        ];
    }

    /**
     * @return array{
     *     today: array{open: float, high: float, low: float, close: float, volume: float},
     *     adv30: float,
     *     bars: array<int, array{open: float, high: float, low: float, close: float, volume: float, date: string}>,
     * }|null
     */
    public function fetchRecentBars(string $ticker, int $lookbackDays = 31, int $limit = 50): ?array
    {
        $to = Carbon::today('America/New_York');
        $from = $to->copy()->subDays($lookbackDays + 5);

        $data = $this->request('/stock/candle', [
            'symbol' => $ticker,
            'resolution' => 'D',
            'from' => $from->timestamp,
            'to' => $to->copy()->endOfDay()->timestamp,
        ]);

        if ($data === null || ($data['s'] ?? null) !== 'ok' || ! isset($data['t']) || ! is_array($data['t'])) {
            return null;
        }

        $bars = [];

        foreach ($data['t'] as $index => $timestamp) {
            $bars[] = [
                'open' => (float) ($data['o'][$index] ?? 0),
                'high' => (float) ($data['h'][$index] ?? 0),
                'low' => (float) ($data['l'][$index] ?? 0),
                'close' => (float) ($data['c'][$index] ?? 0),
                'volume' => (float) ($data['v'][$index] ?? 0),
                'date' => Carbon::createFromTimestamp((int) $timestamp, 'America/New_York')->toDateString(),
            ];
        }

        usort($bars, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        if ($limit > 0) {
            $bars = array_slice($bars, -$limit);
        }

        if (count($bars) < 2) {
            Log::warning('Finnhub daily bars insufficient data.', [
                'ticker' => $ticker,
                'count' => count($bars),
            ]);

            return null;
        }

        $today = $bars[array_key_last($bars)];
        $priorBars = array_slice($bars, 0, -1);
        $advBars = array_slice($priorBars, -30);

        if ($advBars === []) {
            return null;
        }

        $adv30 = array_sum(array_column($advBars, 'volume')) / count($advBars);

        return [
            'today' => [
                'open' => $today['open'],
                'high' => $today['high'],
                'low' => $today['low'],
                'close' => $today['close'],
                'volume' => $today['volume'],
            ],
            'adv30' => $adv30,
            'bars' => $bars,
        ];
    }

    /**
     * @return array{date: string, hour: EarningsReleaseHour}|null
     */
    public function fetchNextEarnings(string $ticker): ?array
    {
        $from = Carbon::today('America/New_York');
        $to = $from->copy()->addDays(90);

        $data = $this->request('/calendar/earnings', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'symbol' => strtoupper(trim($ticker)),
        ]);

        if ($data === null || ! isset($data['earningsCalendar']) || ! is_array($data['earningsCalendar'])) {
            return null;
        }

        $upcoming = collect($data['earningsCalendar'])
            ->filter(function (array $entry) use ($from): bool {
                if (! isset($entry['date'])) {
                    return false;
                }

                return Carbon::parse($entry['date'], 'America/New_York')->greaterThanOrEqualTo($from);
            })
            ->sortBy('date')
            ->first();

        if ($upcoming === null) {
            return null;
        }

        return [
            'date' => (string) $upcoming['date'],
            'hour' => EarningsReleaseHour::tryFromApi($upcoming['hour'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $path, array $params): ?array
    {
        $apiKey = config('vestix.finnhub.api_key');

        if (! $apiKey) {
            Log::warning('Finnhub API key not configured.');

            return null;
        }

        $baseUrl = rtrim(config('vestix.finnhub.base_url'), '/');

        try {
            $response = Http::timeout(30)->get("{$baseUrl}{$path}", [
                ...$params,
                'token' => $apiKey,
            ]);

            if (! $response->successful()) {
                Log::warning('Finnhub request failed.', [
                    'status' => $response->status(),
                    'path' => $path,
                    'params' => $params,
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $exception) {
            Log::error('Finnhub request exception.', [
                'message' => $exception->getMessage(),
                'path' => $path,
                'params' => $params,
            ]);

            return null;
        }
    }
}
