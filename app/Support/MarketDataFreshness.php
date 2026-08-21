<?php

namespace App\Support;

use App\Models\Position;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class MarketDataFreshness
{
    private const STALE_MINUTES = 20;

    private const TICKER_FETCH_TTL_MINUTES = 30;

    private const LEGACY_SYNC_KEY = 'vestix:sync_in_progress';

    private const API_SYNC_BUSY_KEY = 'vestix:api_sync_busy';

    /**
     * True when this user's Forceer API Sync (or equivalent) is running.
     * Cron / other users do not flip this for everyone.
     */
    public static function isSyncInProgress(?int $userId = null): bool
    {
        $userId ??= auth()->id();

        if ($userId === null) {
            return false;
        }

        $startedAt = self::resolveTimestamp(Cache::get(self::userSyncKey($userId)));

        if ($startedAt === null) {
            return false;
        }

        if ($startedAt->lessThan(now()->subMinutes(self::STALE_MINUTES))) {
            self::markSyncFinished($userId);

            return false;
        }

        return true;
    }

    /**
     * True while any vestix:fetch-data run holds the shared Polygon lock path
     * (per-user force sync or scheduled EOD). Used to disable sync buttons without
     * showing "Sync bezig…" for other users.
     */
    public static function isApiSyncBusy(): bool
    {
        self::forgetLegacyGlobalSyncFlag();

        $startedAt = self::resolveTimestamp(Cache::get(self::API_SYNC_BUSY_KEY));

        if ($startedAt === null) {
            return false;
        }

        if ($startedAt->lessThan(now()->subMinutes(self::STALE_MINUTES))) {
            Cache::forget(self::API_SYNC_BUSY_KEY);

            return false;
        }

        return true;
    }

    public static function markSyncStarted(?int $userId = null): void
    {
        self::forgetLegacyGlobalSyncFlag();

        Cache::put(self::API_SYNC_BUSY_KEY, now()->toIso8601String(), now()->addHours(2));

        if ($userId !== null) {
            Cache::put(self::userSyncKey($userId), now()->toIso8601String(), now()->addHours(2));
        }
    }

    public static function markSyncFinished(?int $userId = null): void
    {
        self::forgetLegacyGlobalSyncFlag();
        Cache::forget(self::API_SYNC_BUSY_KEY);

        if ($userId !== null) {
            Cache::forget(self::userSyncKey($userId));
        }
    }

    public static function userSyncKey(int $userId): string
    {
        return 'vestix:sync_in_progress:'.$userId;
    }

    public static function markPositionSyncStarted(int $positionId, ?int $userId = null): void
    {
        Cache::put(self::positionSyncKey($positionId), [
            'started_at' => now()->toIso8601String(),
            'user_id' => $userId,
        ], now()->addHours(2));
    }

    public static function isPositionSyncInProgress(int $positionId): bool
    {
        $payload = Cache::get(self::positionSyncKey($positionId));

        if (! is_array($payload)) {
            return false;
        }

        $startedAt = self::resolveTimestamp($payload['started_at'] ?? null);

        if ($startedAt === null) {
            self::markPositionSyncFinished($positionId);

            return false;
        }

        if ($startedAt->lessThan(now()->subMinutes(self::STALE_MINUTES))) {
            self::markPositionSyncFinished($positionId);

            return false;
        }

        return true;
    }

    public static function markPositionSyncFinished(int $positionId): void
    {
        Cache::forget(self::positionSyncKey($positionId));
    }

    public static function markTickerSyncStarted(int $userId, string $ticker): void
    {
        $ticker = strtoupper(trim($ticker));

        Cache::put(self::tickerSyncKey($userId, $ticker), [
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }

    public static function isTickerSyncInProgress(int $userId, string $ticker): bool
    {
        $ticker = strtoupper(trim($ticker));
        $payload = Cache::get(self::tickerSyncKey($userId, $ticker));

        if (! is_array($payload)) {
            return false;
        }

        $startedAt = self::resolveTimestamp($payload['started_at'] ?? null);

        if ($startedAt === null) {
            self::markTickerSyncFinished($userId, $ticker);

            return false;
        }

        if ($startedAt->lessThan(now()->subMinutes(self::STALE_MINUTES))) {
            self::markTickerSyncFinished($userId, $ticker);

            return false;
        }

        return true;
    }

    public static function markTickerSyncFinished(int $userId, string $ticker): void
    {
        Cache::forget(self::tickerSyncKey($userId, strtoupper(trim($ticker))));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function storeTickerFetchResult(int $userId, string $ticker, array $payload): void
    {
        Cache::put(
            self::tickerFetchKey($userId, $ticker),
            $payload,
            now()->addMinutes(self::TICKER_FETCH_TTL_MINUTES),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pullTickerFetchResult(int $userId, string $ticker): ?array
    {
        $payload = Cache::pull(self::tickerFetchKey($userId, strtoupper(trim($ticker))));

        return is_array($payload) ? $payload : null;
    }

    public static function positionSyncKey(int $positionId): string
    {
        return "vestix:position_sync:{$positionId}";
    }

    public static function tickerSyncKey(int $userId, string $ticker): string
    {
        return 'vestix:ticker_sync:'.$userId.':'.strtoupper(trim($ticker));
    }

    public static function tickerFetchKey(int $userId, string $ticker): string
    {
        return 'vestix:ticker_fetch:'.$userId.':'.strtoupper(trim($ticker));
    }

    public static function lastIntradayQuoteAt(): ?Carbon
    {
        return self::resolveTimestamp(Cache::get('vestix:last_intraday_quote_fetch'));
    }

    public static function markIntradayQuoteFetch(): void
    {
        Cache::put('vestix:last_intraday_quote_fetch', now()->toIso8601String(), now()->addDays(2));
    }

    public static function lastEodFetchAt(): ?Carbon
    {
        return self::resolveTimestamp(Cache::get('vestix:last_api_fetch'));
    }

    public static function lastFetchAt(): ?Carbon
    {
        $timestamps = array_filter([
            self::lastEodFetchAt(),
            self::lastIntradayQuoteAt(),
            self::lastPositionMarketDataUpdate(),
        ]);

        if ($timestamps === []) {
            return null;
        }

        return collect($timestamps)->max();
    }

    public static function subheading(?int $userId = null): string
    {
        if (self::isSyncInProgress($userId)) {
            return 'Sync bezig…';
        }

        $lastFetch = self::lastFetchAt();

        if (! $lastFetch) {
            return 'Nog niet opgehaald';
        }

        return $lastFetch->diffForHumans();
    }

    public static function tooltip(?int $userId = null): string
    {
        if (self::isSyncInProgress($userId)) {
            $userId ??= auth()->id();
            $startedAt = $userId !== null
                ? self::resolveTimestamp(Cache::get(self::userSyncKey($userId)))
                : null;

            if ($startedAt) {
                return 'API-sync gestart '.$startedAt->diffForHumans().'. Dit kan enkele minuten duren.';
            }

            return 'API-sync is bezig. Dit kan enkele minuten duren.';
        }

        if (self::isApiSyncBusy()) {
            return 'Er loopt al een sync. Probeer zo meteen opnieuw.';
        }

        $eodFetch = self::lastEodFetchAt();
        $intradayFetch = self::lastIntradayQuoteAt();

        if ($eodFetch === null && $intradayFetch === null) {
            return 'Nog geen marktdata. Klik om vestix:fetch-data te starten.';
        }

        $parts = [];

        if ($intradayFetch !== null) {
            $parts[] = 'Laatste koersupdate: '.$intradayFetch->format('d-m-Y H:i');
        }

        if ($eodFetch !== null) {
            $parts[] = 'Laatste volledige sync: '.$eodFetch->format('d-m-Y H:i');
        }

        return implode(' | ', $parts);
    }

    public static function statusColor(): string
    {
        $reference = self::lastIntradayQuoteAt() ?? self::lastEodFetchAt() ?? self::lastPositionMarketDataUpdate();

        if ($reference === null) {
            return 'danger';
        }

        if ($reference->greaterThan(now()->subHours(2))) {
            return 'success';
        }

        if ($reference->isToday()) {
            return 'warning';
        }

        if ($reference->greaterThan(now()->subDay())) {
            return 'warning';
        }

        return 'danger';
    }

    private static function forgetLegacyGlobalSyncFlag(): void
    {
        Cache::forget(self::LEGACY_SYNC_KEY);
    }

    private static function lastPositionMarketDataUpdate(): ?Carbon
    {
        $timestamp = Position::open()
            ->whereNotNull('latest_close_price')
            ->whereNotNull('latest_sma_20')
            ->whereNotNull('latest_atr_14')
            ->max('updated_at');

        return self::resolveTimestamp($timestamp);
    }

    private static function resolveTimestamp(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return Carbon::parse($value);
        }

        return null;
    }
}
