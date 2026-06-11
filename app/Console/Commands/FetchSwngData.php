<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\User;
use App\Services\MarketDataFetcher;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFreshness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchSwngData extends Command
{
    protected $signature = 'swng:fetch-data {--user-id= : Gebruiker die een voltooiingsmelding ontvangt}';

    protected $description = 'Haalt EOD slotkoersen, SMA20 en ATR14 op voor open posities en scouts.';

    public function handle(MarketDataFetcher $marketDataFetcher): int
    {
        $lock = Cache::lock(MarketDataFetcher::syncLockKey(), 7200);

        if (! $lock->get()) {
            $this->warn('API-sync draait al. Deze run wordt overgeslagen.');

            return self::SUCCESS;
        }

        try {
            MarketDataFreshness::markSyncStarted();

            $this->info('Sluipschutter Engine gestart: API data ophalen...');

            $positions = Position::tracked()->get();
            $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;

            return $this->runSync($marketDataFetcher, $positions, $userId);
        } finally {
            $lock->release();
            MarketDataFreshness::markSyncFinished();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Position>  $positions
     */
    private function runSync(MarketDataFetcher $marketDataFetcher, $positions, ?int $userId): int
    {
        if ($positions->isEmpty()) {
            $this->info('Geen open posities of scouts gevonden. Engine gaat weer in slaapstand.');

            $marketDataFetcher->touchApiFetchTimestamp();
            $this->notifyCompletion($userId, updated: 0, failed: 0, total: 0);

            return self::SUCCESS;
        }

        $delay = config('swng.alpha_vantage.rate_limit_delay', 12);
        $requiredCalls = $positions->count() * 4;
        $dailyLimit = 25;

        if ($requiredCalls > $dailyLimit) {
            $this->warn("Vereist {$requiredCalls} API-calls maar gratis tier staat ~{$dailyLimit}/dag toe. Gebruik handmatige invoer als fallback.");
        }

        $rows = [];
        $updated = 0;
        $failed = 0;

        foreach ($positions as $index => $position) {
            $this->info("Bezig met ophalen data voor ticker: {$position->ticker}");

            try {
                if ($index > 0) {
                    sleep($delay);
                }

                if ($marketDataFetcher->syncPosition($position, withDelays: true)) {
                    $this->info("Succesvol geüpdatet: {$position->ticker}");
                    $updated++;
                } else {
                    $this->warn("Incomplete data of API limit bereikt voor {$position->ticker}");
                    $failed++;
                }

                $position->refresh();

                $rows[] = [
                    $position->ticker,
                    $position->status,
                    $position->latest_close_price ?? '—',
                    $position->latest_sma_20 ?? '—',
                    $position->latest_atr_14 ?? '—',
                    $position->action_command,
                ];
            } catch (\Throwable $exception) {
                Log::error("Sluipschutter API fout voor {$position->ticker}: {$exception->getMessage()}");

                $this->error("Er ging iets mis bij {$position->ticker}. Check de logs.");
                $failed++;
            }
        }

        $this->table(['Ticker', 'Status', 'Close', 'SMA20', 'ATR14', 'Actie'], $rows);

        $this->info('Alle beschikbare posities zijn wiskundig geanalyseerd!');

        $marketDataFetcher->touchApiFetchTimestamp();
        $this->notifyCompletion($userId, $updated, $failed, $positions->count());

        return self::SUCCESS;
    }

    private function notifyCompletion(?int $userId, int $updated, int $failed, int $total): void
    {
        $recipients = $userId
            ? User::query()->whereKey($userId)->get()
            : User::all();

        if ($recipients->isEmpty()) {
            return;
        }

        $title = 'API-sync voltooid';

        $body = match (true) {
            $total === 0 => 'Geen open posities of scouts om bij te werken.',
            $failed === 0 => "{$updated} van {$total} posities succesvol bijgewerkt.",
            $updated === 0 => "Geen posities bijgewerkt. {$failed} mislukt of onvolledig.",
            default => "{$updated} van {$total} posities bijgewerkt, {$failed} mislukt of onvolledig.",
        };

        $status = ($failed > 0 && $updated === 0) ? 'warning' : 'success';

        FilamentNotifier::send($title, $body, $status, $recipients);
    }
}
