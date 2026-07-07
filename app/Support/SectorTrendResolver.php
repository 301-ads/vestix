<?php

namespace App\Support;

use App\Contracts\DailyBarProvider;
use App\Services\FinnhubService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SectorTrendResolver
{
    /**
     * @var array<string, string>
     */
    private const SECTOR_ALIASES = [
        'Health Care' => 'Healthcare',
        'Financial Services' => 'Financials',
        'Information Technology' => 'Technology',
        'Telecommunication Services' => 'Communication Services',
    ];

    public function __construct(
        private FinnhubService $finnhub,
        private DailyBarProvider $dailyBars,
    ) {}

    /**
     * @return array{
     *     sector_etf: string|null,
     *     sector_close: float|null,
     *     sector_sma_50: float|null,
     *     sector_trend_positive: bool,
     * }|null
     */
    public function resolve(string $ticker, ?string $sectorEtfOverride = null): ?array
    {
        $etf = $this->resolveEtfTicker($ticker, $sectorEtfOverride);

        if ($etf === null) {
            return null;
        }

        $trend = $this->fetchEtfTrend($etf);

        if ($trend === null) {
            return [
                'sector_etf' => $etf,
                'sector_close' => null,
                'sector_sma_50' => null,
                'sector_trend_positive' => false,
            ];
        }

        return [
            'sector_etf' => $etf,
            'sector_close' => $trend['close'],
            'sector_sma_50' => $trend['sma_50'],
            'sector_trend_positive' => $trend['close'] > $trend['sma_50'],
        ];
    }

    public function resolveEtfTicker(string $ticker, ?string $sectorEtfOverride = null): ?string
    {
        $override = strtoupper(trim((string) $sectorEtfOverride));

        if ($override !== '') {
            return $override;
        }

        $profile = $this->finnhub->fetchCompanyProfile($ticker);

        if ($profile === null) {
            Log::warning('Sector ETF resolve failed: Finnhub profile unavailable.', [
                'ticker' => $ticker,
            ]);

            return null;
        }

        $gsector = $profile['gsector'] ?? null;

        if (is_string($gsector) && trim($gsector) !== '') {
            $etf = $this->mapSectorNameToEtf($gsector);

            if ($etf !== null) {
                return $etf;
            }
        }

        $industry = $profile['finnhubIndustry'] ?? null;

        if (is_string($industry) && trim($industry) !== '') {
            $etf = $this->mapSectorNameToEtf($industry)
                ?? $this->mapIndustryNameToEtf($industry);

            if ($etf !== null) {
                return $etf;
            }
        }

        Log::warning('Sector ETF resolve failed: no gsector or mappable finnhubIndustry.', [
            'ticker' => $ticker,
            'gsector' => $gsector,
            'finnhubIndustry' => $industry,
        ]);

        return null;
    }

    private function mapSectorNameToEtf(string $sectorName): ?string
    {
        $mapping = config('vestix.sector_mapping', []);
        $normalized = self::SECTOR_ALIASES[$sectorName] ?? $sectorName;
        $etf = $mapping[$normalized] ?? $mapping[$sectorName] ?? null;

        return is_string($etf) ? strtoupper($etf) : null;
    }

    private function mapIndustryNameToEtf(string $industryName): ?string
    {
        $mapping = config('vestix.industry_mapping', []);
        $etf = $mapping[$industryName] ?? null;

        return is_string($etf) ? strtoupper($etf) : null;
    }

    /**
     * @return array{close: float, sma_50: float}|null
     */
    private function fetchEtfTrend(string $etf): ?array
    {
        return Cache::remember(
            "vestix:sector-etf-trend:{$etf}",
            now()->addHour(),
            function () use ($etf): ?array {
                $bars = $this->dailyBars->fetchRecentBars($etf, lookbackDays: 90, limit: 60);

                if ($bars === null || count($bars['bars']) < 50) {
                    return null;
                }

                $closes = array_column($bars['bars'], 'close');
                $close = round((float) end($closes), 4);
                $sma50 = TechnicalIndicators::smaAtOffset($closes, 50, 0);

                if ($sma50 === null) {
                    return null;
                }

                return [
                    'close' => $close,
                    'sma_50' => round($sma50, 4),
                ];
            },
        );
    }
}
