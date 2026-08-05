<?php

namespace App\Support;

use App\Enums\AutopsyTag;
use App\Models\Position;

/**
 * Visual overrides that separate process discipline from financial outcome.
 */
class AutopsyPresentation
{
    public static function isGoldenBadge(Position $position): bool
    {
        return $position->autopsy_tag === AutopsyTag::FlawlessExecution
            && (float) $position->unrealized_pnl < 0;
    }

    public static function isLuckShot(Position $position): bool
    {
        $tag = $position->autopsy_tag;

        return $tag instanceof AutopsyTag
            && $tag->isError()
            && (float) $position->unrealized_pnl > 0;
    }

    public static function badgeLabel(Position $position): ?string
    {
        if (self::isGoldenBadge($position)) {
            return 'Operatie Geslaagd';
        }

        if (self::isLuckShot($position)) {
            return 'Geluksschot';
        }

        return $position->autopsy_tag?->label();
    }

    public static function badgeColor(Position $position): string
    {
        if (self::isGoldenBadge($position)) {
            return 'warning';
        }

        if (self::isLuckShot($position)) {
            return 'danger';
        }

        return match ($position->autopsy_tag) {
            AutopsyTag::FlawlessExecution => 'success',
            AutopsyTag::QuarantineBreach,
            AutopsyTag::NearMissBlockade,
            AutopsyTag::MicroManagement => 'danger',
            default => 'gray',
        };
    }
}
