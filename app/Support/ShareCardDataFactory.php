<?php

namespace App\Support;

use App\Models\Position;
use App\Services\TradingViewSymbolService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShareCardDataFactory
{
    /**
     * @return array{
     *     ticker: string,
     *     ticker_initial: string,
     *     ticker_icon_url: string|null,
     *     ticker_icon_bg: string|null,
     *     ticker_hue: int,
     *     roi_percentage: float,
     *     roi_formatted: string,
     *     status_label: string,
     *     status_variant: string,
     *     entry_price: string,
     *     current_price: string,
     *     holding_days: int,
     *     subtitle: string,
     * }
     */
    public static function fromPosition(Position $position): array
    {
        $position->loadMissing('asset');

        $roi = $position->unrealized_pnl_percentage;
        $isFreeride = $position->isFreerideSecured();
        $isClosed = $position->status === 'closed';

        $currentPrice = $isClosed
            ? (float) $position->exit_price
            : (float) ($position->latest_close_price ?? $position->entry_price ?? 0);

        $statusLabel = $isFreeride
            ? 'Freeride Secured'
            : ($isClosed ? 'Trade Closed' : 'Open Winner');

        $statusVariant = $isFreeride ? 'freeride' : ($isClosed ? 'closed' : 'open');

        $subtitle = $isFreeride
            ? 'Ongerealiseerde P&L | Volledig afgedekt door wiskundige SL'
            : ($isClosed ? 'Gerealiseerde return' : 'Actuele performance');

        $tickerIcon = self::resolveTickerIcon($position);

        return [
            'ticker' => $position->ticker,
            'ticker_initial' => strtoupper(substr($position->ticker, 0, 1)),
            'ticker_icon_url' => $tickerIcon['url'],
            'ticker_icon_bg' => $tickerIcon['bg'],
            'ticker_hue' => abs(crc32($position->ticker)) % 360,
            'roi_percentage' => $roi,
            'roi_formatted' => ($roi >= 0 ? '+' : '').number_format($roi, 2).'%',
            'status_label' => $statusLabel,
            'status_variant' => $statusVariant,
            'entry_price' => '$'.number_format((float) $position->entry_price, 2),
            'current_price' => '$'.number_format($currentPrice, 2),
            'holding_days' => $position->holdingDays(),
            'subtitle' => $subtitle,
        ];
    }

    /**
     * @return array{url: string|null, bg: string|null}
     */
    public static function resolveTickerIcon(Position $position): array
    {
        $position->loadMissing('asset');

        if ($position->asset?->hasIcon()) {
            $absolutePath = Storage::disk('public')->path($position->asset->icon_path);
            $contents = file_get_contents($absolutePath);

            if ($contents === false) {
                return ['url' => null, 'bg' => null];
            }

            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

            return [
                'url' => self::contentsToDataUri($contents, $extension),
                'bg' => TickerIconPalette::extractFromContents($contents, $extension),
            ];
        }

        $resolved = app(TradingViewSymbolService::class)->resolveSymbol($position->ticker);

        if ($resolved === null || blank($resolved['icon_url'] ?? null)) {
            return ['url' => null, 'bg' => null];
        }

        try {
            $response = Http::timeout(5)->get($resolved['icon_url']);

            if (! $response->successful()) {
                return ['url' => null, 'bg' => null];
            }

            $extension = str_ends_with(strtolower($resolved['icon_url']), '.svg') ? 'svg' : 'png';
            $contents = $response->body();

            return [
                'url' => self::contentsToDataUri($contents, $extension),
                'bg' => TickerIconPalette::extractFromContents($contents, $extension),
            ];
        } catch (\Throwable $exception) {
            Log::debug('Share card ticker icon fetch failed.', [
                'ticker' => $position->ticker,
                'message' => $exception->getMessage(),
            ]);

            return ['url' => null, 'bg' => null];
        }
    }

    /** @deprecated Use resolveTickerIcon() */
    public static function resolveTickerIconDataUri(Position $position): ?string
    {
        return self::resolveTickerIcon($position)['url'];
    }

    private static function contentsToDataUri(string $contents, string $extension): ?string
    {
        $mime = match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
