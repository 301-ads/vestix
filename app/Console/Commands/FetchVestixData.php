<?php

namespace App\Console\Commands;

use App\Jobs\CheckPositionAlertTriggersJob;
use App\Models\Position;
use App\Models\User;
use App\Services\EarningsCalendarSyncService;
use App\Services\MarketDataFetcher;
use App\Support\FilamentNotifier;
use App\Support\MarketDataFreshness;
use App\Support\ScoutSetupAlertService;
use App\Support\ScoutSetupScorecard;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FetchVestixData extends Command
{
    protected $signature = 'vestix:fetch-data
                            {--user-id= : Gebruiker die een voltooiingsmelding ontvangt}
                            {--position-id= : Sync alleen deze scout of positie}
                            {--ticker= : Haal marktdata op voor een ticker (create-formulier)}';

    protected $description = 'Haalt EOD slotkoersen, SMA20, volume en indicatoren op voor open posities en scouts.';

    public function handle(
        MarketDataFetcher $marketDataFetcher,
        ScoutSetupAlertService $scoutSetupAlertService,
    ): int {
        $positionId = $this->option('position-id') ? (int) $this->option('position-id') : null;
        $ticker = $this->option('ticker') ? strtoupper(trim((string) $this->option('ticker'))) : null;
        $userId = $this->option('user-id') ? (int) $this->option('user-id') : null;

        if ($positionId !== null && $ticker !== null) {
            $this->error('Geef --position-id of --ticker, niet beide.');

            return self::FAILURE;
        }

        $lock = Cache::lock(MarketDataFetcher::syncLockKey(), 7200);

        if (! $lock->get()) {
            $this->warn('API-sync draait al. Deze run wordt overgeslagen.');
            $this->clearPendingSyncFlags($positionId, $userId, $ticker);
            $this->notifyLockSkipped($userId, $positionId, $ticker);

            return self::SUCCESS;
        }

        try {
            MarketDataFreshness::markSyncStarted();

            if ($positionId !== null) {
                return $this->runSinglePositionSync(
                    $marketDataFetcher,
                    $scoutSetupAlertService,
                    $positionId,
                    $userId,
                );
            }

            if ($ticker !== null) {
                return $this->runTickerFetch($marketDataFetcher, $ticker, $userId);
            }

            $this->info('Sniper Engine gestart: API data ophalen...');

            $positions = Position::tracked()->get();

            return $this->runBulkSync($marketDataFetcher, $scoutSetupAlertService, $positions, $userId);
        } finally {
            $lock->release();
            MarketDataFreshness::markSyncFinished();
            $this->clearPendingSyncFlags($positionId, $userId, $ticker);
        }
    }

    private function runSinglePositionSync(
        MarketDataFetcher $marketDataFetcher,
        ScoutSetupAlertService $scoutSetupAlertService,
        int $positionId,
        ?int $userId,
    ): int {
        $position = Position::tracked()->find($positionId);

        if ($position === null) {
            $this->warn("Positie {$positionId} niet gevonden of niet actief.");
            $this->notifyPositionCompletion($userId, null, success: false);

            return self::SUCCESS;
        }

        $this->info("Bezig met ophalen data voor ticker: {$position->ticker}");

        try {
            $previousScore = $position->status === 'scout'
                ? ($position->last_setup_score ?? $position->evaluateSetupScore()['totalPoints'])
                : null;

            if ($marketDataFetcher->syncPosition($position, withDelays: false)) {
                $position->refresh();

                if ($position->status === 'scout' && $previousScore !== null) {
                    $newScorecard = $position->evaluateSetupScore();
                    $scoutSetupAlertService->evaluateAndNotify(
                        $position,
                        $previousScore,
                        $newScorecard,
                    );

                    $position->update(['last_setup_score' => $newScorecard['totalPoints']]);
                    $position->refresh();
                }

                $marketDataFetcher->touchApiFetchTimestamp();
                $this->info("Succesvol geüpdatet: {$position->ticker}");

                app(EarningsCalendarSyncService::class)->syncTicker($position->ticker, force: true);
                $position->load('asset');

                $this->info("Earnings sync voltooid voor {$position->ticker}.");
                $this->notifyPositionCompletion($userId, $position, success: true);

                return self::SUCCESS;
            }
        } catch (\Throwable $exception) {
            Log::error("Sniper API fout voor {$position->ticker}: {$exception->getMessage()}");
            $this->error("Er ging iets mis bij {$position->ticker}. Check de logs.");
            $this->notifyPositionCompletion($userId, $position, success: false);

            return self::SUCCESS;
        }

        $this->warn("Incomplete data of API limit bereikt voor {$position->ticker}");
        $this->notifyPositionCompletion($userId, $position, success: false);

        return self::SUCCESS;
    }

    private function runTickerFetch(
        MarketDataFetcher $marketDataFetcher,
        string $ticker,
        ?int $userId,
    ): int {
        $this->info("Bezig met ophalen data voor ticker: {$ticker}");

        try {
            $data = $marketDataFetcher->fetchForTicker($ticker, withDelays: false);

            if ($data === null) {
                $this->warn("Incomplete data of API limit bereikt voor {$ticker}");
                $this->notifyTickerCompletion($userId, $ticker, null);

                return self::SUCCESS;
            }

            if ($userId !== null) {
                MarketDataFreshness::storeTickerFetchResult($userId, $ticker, $data);
            }

            $marketDataFetcher->touchApiFetchTimestamp();
            $this->info("Succesvol opgehaald: {$ticker}");
            $this->notifyTickerCompletion($userId, $ticker, $data);

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error("Sniper API fout voor {$ticker}: {$exception->getMessage()}");
            $this->error("Er ging iets mis bij {$ticker}. Check de logs.");
            $this->notifyTickerCompletion($userId, $ticker, null);

            return self::SUCCESS;
        }
    }

    /**
     * @param  Collection<int, Position>  $positions
     */
    private function runBulkSync(
        MarketDataFetcher $marketDataFetcher,
        ScoutSetupAlertService $scoutSetupAlertService,
        Collection $positions,
        ?int $userId,
    ): int {
        if ($positions->isEmpty()) {
            $this->info('Geen open posities of scouts gevonden. Engine gaat weer in slaapstand.');

            $marketDataFetcher->touchApiFetchTimestamp();
            $this->notifyCompletion($userId, updated: 0, failed: 0, total: 0);

            return self::SUCCESS;
        }

        $delay = (int) config('vestix.polygon.rate_limit_delay', 13);
        $callsPerPosition = max(1, (int) config('vestix.polygon.estimated_calls_per_position', 2));
        $positionCount = $positions->count();
        $estimatedCalls = $positionCount * $callsPerPosition;
        $estimatedMinutes = (int) ceil(($estimatedCalls * $delay) / 60);

        $this->info("Polygon sync: {$positionCount} ticker(s), ~{$estimatedMinutes} min ({$estimatedCalls} calls, {$delay}s ertussen).");

        $rows = [];
        $updated = 0;
        $failed = 0;
        $alertsSent = 0;
        /** @var list<string> $failedTickers */
        $failedTickers = [];

        foreach ($positions as $position) {
            $this->info("Bezig met ophalen data voor ticker: {$position->ticker}");

            try {
                $previousScore = $position->status === 'scout'
                    ? ($position->last_setup_score ?? $position->evaluateSetupScore()['totalPoints'])
                    : null;

                if ($marketDataFetcher->syncPosition($position, withDelays: false)) {
                    $this->info("Succesvol geüpdatet: {$position->ticker}");
                    $updated++;

                    $position->refresh();

                    if ($position->status === 'scout' && $previousScore !== null) {
                        $newScorecard = $position->evaluateSetupScore();
                        $alertsSent += $scoutSetupAlertService->evaluateAndNotify(
                            $position,
                            $previousScore,
                            $newScorecard,
                        );

                        $position->update(['last_setup_score' => $newScorecard['totalPoints']]);
                        $position->refresh();
                    }
                } else {
                    $this->warn("Incomplete data of API limit bereikt voor {$position->ticker}");
                    $failed++;
                    $failedTickers[] = $position->ticker;
                }

                $rows[] = [
                    $position->ticker,
                    $position->status,
                    $position->latest_close_price ?? '—',
                    $position->latest_sma_20 ?? '—',
                    $position->latest_atr_14 ?? '—',
                    $position->status === 'scout'
                        ? ($position->last_setup_score !== null
                            ? $position->last_setup_score.'/'.ScoutSetupScorecard::maxPoints()
                            : '—')
                        : $position->action_command,
                ];
            } catch (\Throwable $exception) {
                Log::error("Sniper API fout voor {$position->ticker}: {$exception->getMessage()}");

                $this->error("Er ging iets mis bij {$position->ticker}. Check de logs.");
                $failed++;
                $failedTickers[] = $position->ticker;
            }
        }

        $this->table(['Ticker', 'Status', 'Close', 'SMA20', 'ATR14', 'Actie/Score'], $rows);

        $this->info('Alle beschikbare posities zijn wiskundig geanalyseerd!');

        if ($alertsSent > 0) {
            $this->info("{$alertsSent} Sniper Telegram-alert(s) verstuurd.");
        }

        $marketDataFetcher->touchApiFetchTimestamp();
        $this->notifyCompletion($userId, $updated, $failed, $positions->count(), $failedTickers);

        CheckPositionAlertTriggersJob::dispatch();

        $earningsSummary = app(EarningsCalendarSyncService::class)->syncTrackedTickers();
        $this->info("Earnings sync: {$earningsSummary['synced']} bijgewerkt, {$earningsSummary['skipped']} overgeslagen, {$earningsSummary['failed']} mislukt.");

        return self::SUCCESS;
    }

    private function clearPendingSyncFlags(?int $positionId, ?int $userId, ?string $ticker): void
    {
        if ($positionId !== null) {
            MarketDataFreshness::markPositionSyncFinished($positionId);
        }

        if ($userId !== null && $ticker !== null && $ticker !== '') {
            MarketDataFreshness::markTickerSyncFinished($userId, $ticker);
        }
    }

    private function notifyLockSkipped(?int $userId, ?int $positionId, ?string $ticker): void
    {
        $recipients = $this->resolveRecipients($userId);

        if ($recipients->isEmpty()) {
            return;
        }

        $label = $ticker
            ?? ($positionId !== null ? Position::query()->find($positionId)?->ticker : null)
            ?? 'marktdata';

        FilamentNotifier::send(
            'Marktdata ophalen overgeslagen',
            "Er loopt al een API-sync. {$label} is niet opnieuw gestart.",
            'warning',
            $recipients,
        );
    }

    private function notifyPositionCompletion(?int $userId, ?Position $position, bool $success): void
    {
        $recipients = $this->resolveRecipients($userId);

        if ($recipients->isEmpty()) {
            return;
        }

        if ($position === null) {
            FilamentNotifier::send(
                'Marktdata onvolledig',
                'De gevraagde positie kon niet worden bijgewerkt.',
                'warning',
                $recipients,
            );

            return;
        }

        if ($success) {
            $close = $position->latest_close_price !== null
                ? '$'.number_format((float) $position->latest_close_price, 2)
                : 'onbekend';

            $body = "{$position->ticker}: koers {$close}, SMA20, SMA50, ATR en RSI opgehaald.";
            $earningsSnippet = $this->formatEarningsSyncSnippet($position);

            if ($earningsSnippet !== null) {
                $body .= ' '.$earningsSnippet;
            }

            FilamentNotifier::send(
                'Marktdata bijgewerkt',
                $body,
                'success',
                $recipients,
            );

            return;
        }

        FilamentNotifier::send(
            'Marktdata onvolledig',
            "Marktdata onvolledig voor {$position->ticker} (Polygon/AV gaf geen complete dataset terug).",
            'warning',
            $recipients,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function notifyTickerCompletion(?int $userId, string $ticker, ?array $data): void
    {
        $recipients = $this->resolveRecipients($userId);

        if ($recipients->isEmpty()) {
            return;
        }

        if ($data === null) {
            FilamentNotifier::send(
                'Marktdata onvolledig',
                "Marktdata onvolledig voor {$ticker} (Polygon/AV gaf geen complete dataset terug).",
                'warning',
                $recipients,
            );

            return;
        }

        $close = '$'.number_format((float) $data['latest_close_price'], 2);

        FilamentNotifier::send(
            'Marktdata klaar',
            "{$ticker}: koers {$close}. Velden worden ingevuld als je het formulier open hebt.",
            'success',
            $recipients,
        );
    }

    /**
     * @param  list<string>  $failedTickers
     */
    private function notifyCompletion(
        ?int $userId,
        int $updated,
        int $failed,
        int $total,
        array $failedTickers = [],
    ): void {
        $recipients = $this->resolveRecipients($userId);

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

        if ($failedTickers !== []) {
            $body .= ' Niet bijgewerkt: '.implode(', ', $failedTickers).'.';
            $body .= ' Sync duurt langer bij veel posities; probeer opnieuw of verhoog POLYGON_RATE_LIMIT_DELAY.';
        }

        $status = match (true) {
            $failed === 0 => 'success',
            $updated === 0 => 'warning',
            default => 'warning',
        };

        FilamentNotifier::send($title, $body, $status, $recipients);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(?int $userId): Collection
    {
        if ($userId !== null) {
            return User::query()->whereKey($userId)->get();
        }

        return User::all();
    }

    private function formatEarningsSyncSnippet(Position $position): ?string
    {
        $asset = $position->asset;

        if ($asset === null || $asset->earnings_fetched_at === null) {
            return null;
        }

        $effectiveDate = $asset->effectiveEarningsDate();

        if ($effectiveDate === null) {
            return 'Earnings: geen aankomende datum gevonden in Finnhub.';
        }

        $hour = $asset->effectiveEarningsHour()->label();
        $daysUntil = $position->daysUntilEarnings();

        $snippet = 'Earnings: '.$effectiveDate->locale('nl')->isoFormat('D MMM Y')." ({$hour})";

        if ($daysUntil !== null) {
            $snippet .= $daysUntil <= 14
                ? " — over {$daysUntil} dagen, let op!"
                : " — over {$daysUntil} dagen.";
        }

        return $snippet;
    }
}
