<?php

namespace App\Console\Commands;

use App\Contracts\QuoteProvider;
use App\Models\Position;
use App\Support\ScoutEntryProximity;
use App\Support\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WatchScouts extends Command
{
    public const LOCK_KEY = 'swng:watch-scouts';

    protected $signature = 'swng:watch-scouts';

    protected $description = 'Pollt live koersen voor scouts en stuurt Telegram-alerts bij A-setup.';

    public function handle(QuoteProvider $quoteProvider): int
    {
        if (! $this->isConfigured()) {
            $this->warn('Telegram of koers-API niet geconfigureerd — watcher overgeslagen.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK_KEY, 600);

        if (! $lock->get()) {
            $this->warn('Scout-watcher draait al. Deze run wordt overgeslagen.');

            return self::SUCCESS;
        }

        try {
            return $this->watch($quoteProvider);
        } finally {
            $lock->release();
        }
    }

    private function isConfigured(): bool
    {
        $hasTelegram = filled(config('swng.telegram.bot_token'))
            && filled(config('swng.telegram.chat_id'));

        $hasQuoteApi = filled(config('swng.polygon.api_key'))
            || filled(config('swng.alpha_vantage.api_key'));

        return $hasTelegram && $hasQuoteApi;
    }

    private function watch(QuoteProvider $quoteProvider): int
    {
        $scouts = Position::scout()->awaitingTelegramAlert()->get();

        if ($scouts->isEmpty()) {
            $this->info('Geen scouts in de wachtrij. Watcher gaat weer slapen.');

            return self::SUCCESS;
        }

        $this->info("Scout-watcher gestart: {$scouts->count()} scout(s) te controleren.");

        $chunkSize = config('swng.scout_watcher.quotes_per_minute', 4);
        $chunkPause = config('swng.scout_watcher.chunk_pause_seconds', 60);
        $proximityPercent = config('swng.scout_watcher.entry_proximity_percent', 0.5);
        $minScore = config('swng.scout_watcher.min_score_points', 6);

        $chunks = $scouts->chunk($chunkSize);
        $totalChunks = $chunks->count();
        $alertsSent = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            $this->info('Chunk '.($chunkIndex + 1)."/{$totalChunks} ({$chunk->count()} scout(s))");

            foreach ($chunk as $position) {
                $result = $this->processScout($position, $quoteProvider, $proximityPercent, $minScore);

                if ($result === 'alert') {
                    $alertsSent++;
                }
            }

            if ($chunkIndex < $totalChunks - 1) {
                $this->line("Pauze {$chunkPause}s voor Polygon rate-limit...");
                sleep($chunkPause);
            }
        }

        $this->info("Scout-watcher klaar. {$alertsSent} alert(s) verstuurd.");

        return self::SUCCESS;
    }

    private function processScout(
        Position $position,
        QuoteProvider $quoteProvider,
        float $proximityPercent,
        int $minScore,
    ): ?string {
        $ticker = $position->ticker;

        if ($position->latest_sma_20 === null || $position->scout_rsi === null) {
            $this->line("  [{$ticker}] Overgeslagen — wacht op EOD marktdata.");

            return null;
        }

        $livePrice = $quoteProvider->fetchLivePrice($ticker);

        if ($livePrice === null) {
            $this->warn("  [{$ticker}] Geen live prijs ontvangen.");

            return null;
        }

        $entry = (float) $position->entry_price;

        if (! ScoutEntryProximity::isNearEntry($livePrice, $entry, $proximityPercent)) {
            $this->line("  [{$ticker}] Live \${$livePrice} buiten entry-marge van \${$entry}.");

            return null;
        }

        $scorecard = $position->evaluateSetupScore();

        if ($scorecard['hardFailReasons'] !== [] || $scorecard['totalPoints'] < $minScore) {
            $this->line("  [{$ticker}] Score {$scorecard['totalPoints']}/{$scorecard['maxPoints']} — geen alert.");

            return null;
        }

        $message = sprintf(
            '🚨 A+ SETUP BEREIKT: %s raakt entry $%s. Score: %d/%d. Live: $%s. Open je broker!',
            $ticker,
            number_format($entry, 2),
            $scorecard['totalPoints'],
            $scorecard['maxPoints'],
            number_format($livePrice, 2),
        );

        if (! TelegramNotifier::send($message)) {
            $this->warn("  [{$ticker}] Telegram-verzending mislukt.");

            return null;
        }

        $position->update(['telegram_alert_sent_at' => now()]);

        $this->info("  [{$ticker}] Alert verstuurd! Score {$scorecard['totalPoints']}/{$scorecard['maxPoints']}.");

        return 'alert';
    }
}
