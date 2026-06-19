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
     *     share_text: string,
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
            'share_text' => self::buildShareText(
                $position->ticker,
                ($roi >= 0 ? '+' : '').number_format($roi, 2).'%',
                $statusLabel,
                '$'.number_format((float) $position->entry_price, 2),
                '$'.number_format($currentPrice, 2),
                $position->holdingDays(),
            ),
            'share_filename' => 'vestix-'.$position->ticker.'-share.png',
        ];
    }

    /**
     * @return array{
     *     ticker: string,
     *     ticker_initial: string,
     *     ticker_icon_url: string|null,
     *     ticker_icon_bg: string|null,
     *     ticker_hue: int,
     *     score: int,
     *     max_score: int,
     *     score_formatted: string,
     *     grade: string,
     *     grade_label: string,
     *     status_variant: string,
     *     close_price: string,
     *     sma_20: string,
     *     rsi_formatted: string,
     *     entry_price: string|null,
     *     stop_loss: string|null,
     *     subtitle: string,
     *     share_text: string,
     *     share_filename: string,
     * }
     */
    public static function fromScout(Position $position): array
    {
        $position->loadMissing('asset');

        $score = $position->evaluateSetupScore();
        $tickerIcon = self::resolveTickerIcon($position);

        $closePrice = $position->latest_close_price !== null
            ? '$'.number_format((float) $position->latest_close_price, 2)
            : '—';

        $sma20 = $position->latest_sma_20 !== null
            ? '$'.number_format((float) $position->latest_sma_20, 2)
            : '—';

        $rsiFormatted = $position->scout_rsi !== null
            ? number_format((float) $position->scout_rsi, 1)
            : '—';

        $entryPrice = $position->entry_price !== null
            ? '$'.number_format((float) $position->entry_price, 2)
            : null;

        $stopLoss = $position->new_sl !== null
            ? '$'.number_format((float) $position->new_sl, 2)
            : null;

        $scoreFormatted = $score['totalPoints'].'/'.$score['maxPoints'];

        return [
            'ticker' => $position->ticker,
            'ticker_initial' => strtoupper(substr($position->ticker, 0, 1)),
            'ticker_icon_url' => $tickerIcon['url'],
            'ticker_icon_bg' => $tickerIcon['bg'],
            'ticker_hue' => abs(crc32($position->ticker)) % 360,
            'score' => $score['totalPoints'],
            'max_score' => $score['maxPoints'],
            'score_formatted' => $scoreFormatted,
            'grade' => $score['grade'],
            'grade_label' => $score['gradeLabel'],
            'status_variant' => self::scoutStatusVariant($score['grade']),
            'close_price' => $closePrice,
            'sma_20' => $sma20,
            'rsi_formatted' => $rsiFormatted,
            'entry_price' => $entryPrice,
            'stop_loss' => $stopLoss,
            'subtitle' => 'Setup Radar · Wiskundige scorecard',
            'share_text' => self::buildScoutShareText(
                $position->ticker,
                $score['gradeLabel'],
                $scoreFormatted,
                $closePrice,
                $sma20,
                $rsiFormatted,
                $entryPrice,
                $stopLoss,
            ),
            'share_filename' => 'vestix-'.$position->ticker.'-setup.png',
        ];
    }

    private static function scoutStatusVariant(string $grade): string
    {
        return match ($grade) {
            'A+' => 'a-plus',
            'A-' => 'a-minus',
            default => 'bc',
        };
    }

    private static function buildScoutShareText(
        string $ticker,
        string $gradeLabel,
        string $scoreFormatted,
        string $closePrice,
        string $sma20,
        string $rsiFormatted,
        ?string $entryPrice,
        ?string $stopLoss,
    ): string {
        $lines = [
            "{$ticker} {$gradeLabel} · {$scoreFormatted}",
            "Close {$closePrice} · SMA 20 {$sma20} · RSI {$rsiFormatted}",
        ];

        if ($entryPrice !== null || $stopLoss !== null) {
            $lines[] = trim(sprintf(
                'Entry %s · SL %s',
                $entryPrice ?? '—',
                $stopLoss ?? '—',
            ));
        }

        $lines[] = '';
        $lines[] = 'vestix.io — Vergeet Geluk. Gebruik Wiskunde.';

        return implode("\n", $lines);
    }

    private static function buildShareText(
        string $ticker,
        string $roiFormatted,
        string $statusLabel,
        string $entryPrice,
        string $currentPrice,
        int $holdingDays,
    ): string {
        $holdingLabel = $holdingDays === 1 ? '1 dag holding' : "{$holdingDays} dagen holding";

        return implode("\n", [
            "{$ticker} {$roiFormatted} · {$statusLabel}",
            "Entry {$entryPrice} → Huidig {$currentPrice}",
            $holdingLabel,
            '',
            'vestix.io — Vergeet Geluk. Gebruik Wiskunde.',
        ]);
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
