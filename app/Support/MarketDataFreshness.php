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

    public static function isSyncInProgress(): bool
    {
        $startedAt = self::resolveTimestamp(Cache::get('vestix:sync_in_progress'));

        if ($startedAt === null) {
            return false;
        }

        if ($startedAt->lessThan(now()->subMinutes(self::STALE_MINUTES))) {
            self::markSyncFinished();

            return false;
        }

        return true;
    }

    public static function markSyncStarted(): void
    {
        Cache::put('vestix:sync_in_progress', now()->toIso8601String(), now()->addHours(2));
    }

    public static function markSyncFinished(): void
    {
        Cache::forget('vestix:sync_in_progress');
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

    public static function subheading(): string
    {
        if (self::isSyncInProgress()) {
            return 'Sync bezig…';
        }

        $lastFetch = self::lastFetchAt();

        if (! $lastFetch) {
            return 'Nog niet opgehaald';
        }

        return $lastFetch->diffForHumans();
    }

    public static function tooltip(): string
    {
        if (self::isSyncInProgress()) {
            $startedAt = self::resolveTimestamp(Cache::get('vestix:sync_in_progress'));

            if ($startedAt) {
                return 'API-sync gestart '.$startedAt->diffForHumans().'. Dit kan enkele minuten duren.';
            }

            return 'API-sync is bezig. Dit kan enkele minuten duren.';
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
