<?php

namespace App\Support;

/**
 * Feature gate for Legacy Archief UI (Posities tab).
 * is_legacy data and scopes remain; flip config to re-enable the surface.
 */
class LegacyArchive
{
    public static function enabled(): bool
    {
        return (bool) config('vestix.legacy_archive.enabled', false);
    }
}
