<?php

namespace App\Services;

use App\Contracts\QuoteProvider;
use App\Models\Position;
use Illuminate\Support\Facades\Cache;

class MarketDataFetcher
{
    public function __construct(
        private AlphaVantageService $alphaVantage,
        private QuoteProvider $quoteProvider,
    ) {}

    /**
     * @return array{
     *     latest_close_price: float,
     *     latest_sma_20: float,
     *     sma_20_five_days_ago: float|null,
     *     latest_sma_50: float,
     *     latest_atr_14: float,
     *     scout_rsi: float,
     * }|null
     */
    public function fetchForTicker(string $ticker, bool $withDelays = true): ?array
    {
        $delay = config('swng.alpha_vantage.intra_request_delay', 2);

        $close = $this->fetchQuotePrice($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $smaPair = $this->alphaVantage->fetchSma20Pair($ticker);
        $sma = $smaPair['latest'];

        if ($withDelays) {
            sleep($delay);
        }

        $sma50 = $this->alphaVantage->fetchSma50($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $atr = $this->alphaVantage->fetchAtr14($ticker);

        if ($withDelays) {
            sleep($delay);
        }

        $rsi = $this->alphaVantage->fetchRsi14($ticker);

        if ($close === null || $sma === null || $sma50 === null || $atr === null || $rsi === null) {
            return null;
        }

        $this->touchApiFetchTimestamp();

        return [
            'latest_close_price' => $close,
            'latest_sma_20' => $sma,
            'sma_20_five_days_ago' => $smaPair['five_days_ago'],
            'latest_sma_50' => $sma50,
            'latest_atr_14' => $atr,
            'scout_rsi' => $rsi,
        ];
    }

    public function syncPosition(Position $position, bool $withDelays = true): bool
    {
        $data = $this->fetchForTicker($position->ticker, $withDelays);

        if ($data === null) {
            return false;
        }

        $position->update($data);

        return true;
    }

    private function fetchQuotePrice(string $ticker): ?float
    {
        return $this->quoteProvider->fetchLivePrice($ticker)
            ?? $this->alphaVantage->fetchQuote($ticker);
    }

    public function touchApiFetchTimestamp(): void
    {
        Cache::put('swng:last_api_fetch', now()->toIso8601String(), now()->addDays(30));
    }

    public static function syncLockKey(): string
    {
        return 'swng:api-sync';
    }
}
