<?php

namespace App\Console\Commands;

use App\Services\AlphaVantageService;
use App\Services\FinnhubService;
use App\Support\UsMarketSession;
use Illuminate\Console\Command;

class CompareMarketQuotes extends Command
{
    protected $signature = 'vestix:compare-market-quotes {ticker=TKO : Ticker om te vergelijken}';

    protected $description = 'Vergelijk slotkoersen en laatste daily bar van Finnhub, Alpha Vantage en Polygon';

    public function handle(
        FinnhubService $finnhub,
        AlphaVantageService $alphaVantage,
    ): int {
        $ticker = strtoupper((string) $this->argument('ticker'));

        $this->info("Marktdata-vergelijking voor {$ticker}");
        $this->line('Verwachte laatste sessie: '.UsMarketSession::expectedLastCompletedSessionDate()->toDateString());
        $this->line('Na US close: '.(UsMarketSession::isAfterMarketClose() ? 'ja' : 'nee'));
        $this->newLine();

        $rows = [];

        if (config('vestix.finnhub.api_key')) {
            $quote = $finnhub->fetchQuote($ticker);
            $bars = $finnhub->fetchRecentBars($ticker, lookbackDays: 90, limit: 5);
            $lastBar = $bars['bars'][array_key_last($bars['bars'] ?? [])] ?? null;

            $rows[] = [
                'Finnhub quote',
                $quote['close'] ?? '—',
                $quote['high'] ?? '—',
                $quote['low'] ?? '—',
            ];
            $rows[] = [
                'Finnhub candle',
                $lastBar['close'] ?? '—',
                $lastBar['high'] ?? '—',
                $lastBar['date'] ?? '—',
            ];
        } else {
            $this->warn('FINNHUB_API_KEY ontbreekt — sla Finnhub over.');
        }

        if (config('vestix.alpha_vantage.api_key')) {
            $quote = $alphaVantage->fetchGlobalQuote($ticker);

            $rows[] = [
                'Alpha Vantage GLOBAL_QUOTE',
                $quote['close'] ?? '—',
                $quote['high'] ?? '—',
                $quote['low'] ?? '—',
            ];
        } else {
            $this->warn('ALPHA_VANTAGE_API_KEY ontbreekt — sla Alpha Vantage over.');
        }

        if ($rows === []) {
            $this->error('Geen API keys geconfigureerd — voeg FINNHUB_API_KEY en/of ALPHA_VANTAGE_API_KEY toe.');

            return self::FAILURE;
        }

        $this->table(['Bron', 'Close', 'High', 'Low / datum'], $rows);
        $this->newLine();
        $this->line('Vergelijk close met TradingView. Finnhub quote is de primaire bron na US close.');

        return self::SUCCESS;
    }
}
