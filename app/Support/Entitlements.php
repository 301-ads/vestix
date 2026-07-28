<?php

namespace App\Support;

use App\Models\User;

/**
 * Free-First entitlements hook. Monetisation can gate features later
 * without rewriting the free automation loop.
 */
class Entitlements
{
    public const FEATURE_SNIPER_SCANNER = 'sniper_scanner';

    public const FEATURE_SQUAD_CLONE = 'squad_clone';

    public const FEATURE_WEEKLY_EDGE_DIGEST = 'weekly_edge_digest';

    public const FEATURE_IBKR_RECONCILE = 'ibkr_reconcile';

    public const FEATURE_EXTRA_STRATEGY_TAGS = 'extra_strategy_tags';

    /**
     * @return list<string>
     */
    public static function freeFeatures(): array
    {
        return [
            self::FEATURE_SNIPER_SCANNER,
            self::FEATURE_SQUAD_CLONE,
            self::FEATURE_WEEKLY_EDGE_DIGEST,
            self::FEATURE_IBKR_RECONCILE,
            self::FEATURE_EXTRA_STRATEGY_TAGS,
        ];
    }

    public static function allows(User $user, string $feature): bool
    {
        $overrides = config('vestix.entitlements.overrides', []);

        if (array_key_exists($feature, $overrides)) {
            return (bool) $overrides[$feature];
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $plan = (string) (data_get($user->ui_preferences, 'plan', 'free'));

        if ($plan === 'free') {
            return in_array($feature, self::freeFeatures(), true);
        }

        // Future paid plans: allow all known features by default.
        return true;
    }
}
