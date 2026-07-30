<?php

namespace App\Support;

/**
 * Feature gate for Trade Journal UI (notes + chart screenshots).
 * Data columns remain; flip config to re-enable the surfaces.
 */
class TradeJournal
{
    public static function enabled(): bool
    {
        return (bool) config('vestix.trade_journal.enabled', false);
    }
}
