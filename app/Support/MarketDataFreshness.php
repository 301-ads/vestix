<?php

namespace App\Support;

use App\Models\Position;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class MarketDataFreshness
{
    public static function isSyncInProgress(): bool
    {
        return Cache::has('swng:sync_in_progress');
    }

    public static function markSyncStarted(): void
    {
        Cache::put('swng:sync_in_progress', now()->toIso8601String(), now()->addHours(2));
    }

    public static function markSyncFinished(): void
    {
        Cache::forget('swng:sync_in_progress');
    }

    public static function lastFetchAt(): ?Carbon
    {
        return self::resolveTimestamp(Cache::get('swng:last_api_fetch'))
            ?? self::lastPositionMarketDataUpdate();
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
            $startedAt = self::resolveTimestamp(Cache::get('swng:sync_in_progress'));

            if ($startedAt) {
                return 'API-sync gestart '.$startedAt->diffForHumans().'. Dit kan enkele minuten duren.';
            }

            return 'API-sync is bezig. Dit kan enkele minuten duren.';
        }

        $lastFetch = self::lastFetchAt();

        if (! $lastFetch) {
            return 'Nog geen marktdata. Klik om swng:fetch-data te starten.';
        }

        return 'Laatste fetch: '.$lastFetch->format('d-m-Y H:i');
    }

    public static function statusColor(): string
    {
        $lastFetch = self::lastFetchAt();

        if (! $lastFetch) {
            return 'danger';
        }

        if ($lastFetch->isToday()) {
            return 'success';
        }

        if ($lastFetch->greaterThan(now()->subDay())) {
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
