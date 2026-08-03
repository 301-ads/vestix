<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Request-scoped memo of open positions for dashboard/stats widgets.
 * Cleared automatically between HTTP requests (no Octane).
 */
final class OpenPositionsSnapshot
{
    /** @var array<int, Collection<int, Position>> */
    private static array $memo = [];

    /**
     * @return Collection<int, Position>
     */
    public static function forUser(int $userId): Collection
    {
        return self::$memo[$userId] ??= Position::query()
            ->open()
            ->nonLegacy()
            ->forUser($userId)
            ->with('asset')
            ->get();
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
