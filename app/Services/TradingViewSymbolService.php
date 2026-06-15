<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TradingViewSymbolService
{
    /** @var list<string> */
    private const US_EXCHANGES = ['NYSE', 'NASDAQ', 'AMEX', 'NYSEARCA', 'BATS', 'OTC'];

    /**
     * @return array<string, string> Ticker => label
     */
    public function searchForForm(string $query, int $limit = 15): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $results = $this->searchSymbols($query);

        if ($results === null || $results === []) {
            return [];
        }

        $ranked = [];

        foreach ($results as $result) {
            $symbol = $this->normalizeSymbol($result['symbol'] ?? '');

            if ($symbol === '') {
                continue;
            }

            $exchange = (string) ($result['exchange'] ?? '');
            $description = $this->cleanDescription($result['description'] ?? $symbol);
            $label = $exchange !== ''
                ? "{$symbol} — {$description} ({$exchange})"
                : "{$symbol} — {$description}";

            $ranked[] = [
                'symbol' => $symbol,
                'label' => $label,
                'score' => $this->scoreSearchResult($result, $query),
            ];
        }

        usort($ranked, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        $options = [];

        foreach ($ranked as $entry) {
            if (isset($options[$entry['symbol']])) {
                continue;
            }

            $options[$entry['symbol']] = $entry['label'];

            if (count($options) >= $limit) {
                break;
            }
        }

        return $options;
    }

    /**
     * @return array{
     *     name: string,
     *     logoid: string,
     *     exchange: string,
     *     icon_url: string,
     * }|null
     */
    public function resolveSymbol(string $ticker): ?array
    {
        $ticker = strtoupper(trim($ticker));
        $match = $this->findBestMatch($ticker);

        if ($match === null) {
            return null;
        }

        $logoid = $match['logoid'] ?? null;

        if (blank($logoid)) {
            return null;
        }

        $logoCdnUrl = rtrim(config('vestix.tradingview.logo_cdn_url'), '/');

        return [
            'name' => $this->cleanDescription($match['description'] ?? $ticker),
            'logoid' => $logoid,
            'exchange' => $match['exchange'] ?? '',
            'icon_url' => "{$logoCdnUrl}/{$logoid}.svg",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBestMatch(string $ticker): ?array
    {
        $results = $this->searchSymbols($ticker);

        if ($results === null) {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MIN;
        $bestUsExchange = false;

        foreach ($results as $result) {
            if ($this->normalizeSymbol($result['symbol'] ?? '') !== $ticker) {
                continue;
            }

            $exchange = (string) ($result['exchange'] ?? '');
            $isUsExchange = in_array($exchange, self::US_EXCHANGES, true);
            $score = $this->scoreSearchResult($result, $ticker);

            if (
                $best === null
                || $score > $bestScore
                || ($score === $bestScore && $isUsExchange && ! $bestUsExchange)
            ) {
                $best = $result;
                $bestScore = $score;
                $bestUsExchange = $isUsExchange;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function scoreSearchResult(array $result, string $query): int
    {
        $symbol = $this->normalizeSymbol($result['symbol'] ?? '');
        $exchange = (string) ($result['exchange'] ?? '');
        $description = strtolower($this->cleanDescription($result['description'] ?? ''));
        $normalizedQuery = strtoupper(trim($query));
        $score = 0;

        if ($symbol === $normalizedQuery) {
            $score += 100;
        } elseif (str_starts_with($symbol, $normalizedQuery)) {
            $score += 80;
        } elseif (str_contains($symbol, $normalizedQuery)) {
            $score += 40;
        }

        if ($normalizedQuery !== '' && str_contains($description, strtolower($normalizedQuery))) {
            $score += 20;
        }

        if ($result['is_primary_listing'] ?? false) {
            $score += 10;
        }

        if (in_array($exchange, self::US_EXCHANGES, true)) {
            $score += 8;
        }

        if (($result['country'] ?? '') === 'US') {
            $score += 2;
        }

        return $score;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function searchSymbols(string $ticker): ?array
    {
        $baseUrl = rtrim(config('vestix.tradingview.symbol_search_url'), '/');

        try {
            $response = Http::timeout(30)
                ->withHeaders($this->requestHeaders())
                ->get($baseUrl, [
                    'text' => $ticker,
                    'hl' => 1,
                    'exchange' => '',
                    'lang' => 'en',
                    'type' => 'stock',
                    'domain' => 'production',
                ]);

            if (! $response->successful()) {
                Log::warning('TradingView symbol search failed.', [
                    'ticker' => $ticker,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $results = $response->json();

            return is_array($results) ? $results : null;
        } catch (\Throwable $exception) {
            Log::error('TradingView symbol search exception.', [
                'ticker' => $ticker,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0',
            'Origin' => 'https://www.tradingview.com',
        ];
    }

    private function normalizeSymbol(mixed $symbol): string
    {
        return strtoupper(trim(strip_tags((string) $symbol)));
    }

    private function cleanDescription(mixed $description): string
    {
        return trim(strip_tags((string) $description));
    }
}
