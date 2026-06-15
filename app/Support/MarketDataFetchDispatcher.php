<?php

namespace App\Support;

use App\Models\Position;

class MarketDataFetchDispatcher
{
    public static function dispatchPositionFetch(Position $position): bool
    {
        if (MarketDataFreshness::isPositionSyncInProgress($position->id)) {
            FilamentNotifier::send(
                title: 'Marktdata ophalen bezig',
                body: "Er wordt al marktdata opgehaald voor {$position->ticker}.",
                status: 'warning',
            );

            return false;
        }

        if (MarketDataFreshness::isSyncInProgress()) {
            FilamentNotifier::send(
                title: 'API-sync bezig',
                body: 'Er loopt al een marktdata-sync. Wacht even en probeer opnieuw.',
                status: 'warning',
            );

            return false;
        }

        $userId = auth()->id();

        MarketDataFreshness::markPositionSyncStarted($position->id, $userId);

        BackgroundArtisan::dispatch('vestix:fetch-data', array_filter([
            'position-id' => $position->id,
            'user-id' => $userId,
        ]));

        FilamentNotifier::send(
            title: 'Marktdata ophalen gestart',
            body: "{$position->ticker} wordt op de achtergrond bijgewerkt. Je krijgt een melding zodra het klaar is.",
            status: 'info',
        );

        return true;
    }

    public static function dispatchTickerFetch(string $ticker, ?int $userId = null): bool
    {
        $ticker = strtoupper(trim($ticker));
        $userId ??= auth()->id();

        if ($ticker === '' || $userId === null) {
            FilamentNotifier::send(
                title: 'Ticker ontbreekt',
                body: 'Kies eerst een ticker voordat je marktdata ophaalt.',
                status: 'warning',
            );

            return false;
        }

        if (MarketDataFreshness::isTickerSyncInProgress($userId, $ticker)) {
            FilamentNotifier::send(
                title: 'Marktdata ophalen bezig',
                body: "Er wordt al marktdata opgehaald voor {$ticker}.",
                status: 'warning',
            );

            return false;
        }

        if (MarketDataFreshness::isSyncInProgress()) {
            FilamentNotifier::send(
                title: 'API-sync bezig',
                body: 'Er loopt al een marktdata-sync. Wacht even en probeer opnieuw.',
                status: 'warning',
            );

            return false;
        }

        MarketDataFreshness::markTickerSyncStarted($userId, $ticker);

        BackgroundArtisan::dispatch('vestix:fetch-data', [
            'ticker' => $ticker,
            'user-id' => $userId,
        ]);

        FilamentNotifier::send(
            title: 'Marktdata ophalen gestart',
            body: "{$ticker} wordt op de achtergrond opgehaald. Je krijgt een melding zodra het klaar is.",
            status: 'info',
        );

        return true;
    }
}
