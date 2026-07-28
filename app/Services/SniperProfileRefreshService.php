<?php

namespace App\Services;

use App\Models\SniperLiquidityCache;
use Illuminate\Support\Facades\Log;

class SniperProfileRefreshService
{
    public function __construct(
        private readonly FinnhubService $finnhub,
    ) {}

    /**
     * @return array{refreshed: int, skipped: int}
     */
    public function refresh(?int $limit = null): array
    {
        if (! (bool) config('vestix.sniper_scanner.enabled')) {
            return ['refreshed' => 0, 'skipped' => 0];
        }

        $limit ??= (int) config('vestix.sniper_scanner.profile_refresh_per_run', 150);
        $minVolume = (int) config('vestix.sniper_scanner.min_volume', 1_000_000);
        $delay = max(0, (int) config('vestix.finnhub.rate_limit_delay', 1));
        $allowlist = array_map('strtoupper', config('vestix.sniper_scanner.etf_allowlist', []));

        $candidates = SniperLiquidityCache::query()
            ->where('enabled', true)
            ->where(function ($query) use ($minVolume, $allowlist): void {
                $query->where('last_volume', '>', $minVolume)
                    ->orWhereIn('ticker', $allowlist);
            })
            ->orderByRaw('CASE WHEN market_cap IS NULL THEN 0 ELSE 1 END')
            ->orderBy('market_cap_fetched_at')
            ->orderByDesc('last_volume')
            ->limit($limit)
            ->get();

        $refreshed = 0;
        $skipped = 0;

        foreach ($candidates as $index => $row) {
            if ($index > 0 && $delay > 0) {
                sleep($delay);
            }

            $profile = $this->finnhub->fetchCompanyProfile($row->ticker);

            if ($profile === null) {
                $skipped++;

                continue;
            }

            $marketCap = $profile['marketCapitalization'] ?? null;
            // Finnhub returns market cap in millions.
            $capUsd = is_numeric($marketCap) ? ((float) $marketCap) * 1_000_000 : null;

            $type = $row->asset_type;
            $name = strtoupper((string) ($profile['name'] ?? ''));
            $industry = strtoupper((string) ($profile['finnhubIndustry'] ?? ''));

            if (in_array($row->ticker, $allowlist, true)) {
                $type = 'ETF';
            } elseif ($type === null) {
                $type = (str_contains($name, 'ETF') || str_contains($industry, 'ETF'))
                    ? 'ETF'
                    : 'CS';
            }

            $row->update([
                'asset_type' => $type,
                'market_cap' => $capUsd,
                'market_cap_fetched_at' => now(),
            ]);

            $refreshed++;
        }

        Log::info('Sniper profiles refreshed.', compact('refreshed', 'skipped'));

        return compact('refreshed', 'skipped');
    }
}
