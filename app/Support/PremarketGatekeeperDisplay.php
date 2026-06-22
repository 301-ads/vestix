<?php

namespace App\Support;

use App\Enums\PremarketGapStatus;
use App\Models\Position;
use Illuminate\Support\Carbon;

class PremarketGatekeeperDisplay
{
    public static function isRelevant(Position $position): bool
    {
        return $position->isArmedForEntryToday()
            || ($position->premarket_checked_at !== null
                && $position->premarket_checked_at->toDateString() === Carbon::now('America/New_York')->toDateString());
    }

    public static function gapStatusLabel(Position $position): ?string
    {
        if (! self::isRelevant($position) || $position->premarket_gap_status === null) {
            return $position->isArmedForEntryToday() && $position->premarket_checked_at === null
                ? 'Wacht op check'
                : null;
        }

        return $position->premarket_gap_status->label();
    }

    public static function gapStatusColor(Position $position): string
    {
        if ($position->premarket_gap_status instanceof PremarketGapStatus) {
            return $position->premarket_gap_status->badgeColor();
        }

        return $position->isArmedForEntryToday() ? 'info' : 'gray';
    }

    public static function rowClass(Position $position): ?string
    {
        if ($position->hasPremarketGapUpRisk()) {
            return 'scout-premarket-gap-up';
        }

        return null;
    }

    /**
     * @return array{label: string, value: string, valueColor: string|null, description: string|null, descriptionColor: string, cardVariant: string}|null
     */
    public static function cockpitCardData(Position $position): ?array
    {
        if (! self::isRelevant($position)) {
            return null;
        }

        $status = $position->premarket_gap_status;
        $value = $position->premarket_price !== null
            ? '$'.number_format((float) $position->premarket_price, 2)
            : '—';

        $description = null;
        $descriptionColor = 'gray';
        $valueColor = null;
        $cardVariant = 'blue';

        if ($status === PremarketGapStatus::GapUp) {
            $trigger = $position->premarket_entry_trigger ?? $position->entry_price;
            $description = sprintf(
                'Boven entry-trigger ($%s). Risico op chasing!',
                number_format((float) $trigger, 2),
            );
            $descriptionColor = 'danger';
            $valueColor = 'danger';
            $cardVariant = 'amber';
        } elseif ($status === PremarketGapStatus::Ok) {
            $description = 'Pre-market op of onder je entry-trigger.';
            $descriptionColor = 'success';
            $valueColor = 'success';
        } elseif ($status === PremarketGapStatus::GapDown) {
            $description = 'Onder entry-trigger — buy-stop triggert niet bij open.';
            $descriptionColor = 'warning';
        } elseif ($status === PremarketGapStatus::Unavailable) {
            $description = 'Geen live quote beschikbaar.';
            $descriptionColor = 'gray';
        } elseif ($position->premarket_checked_at === null) {
            $description = 'Gatekeeper check om 15:00 NL.';
            $descriptionColor = 'info';
        }

        if ($position->premarket_gap_pct !== null && $status === PremarketGapStatus::GapUp) {
            $description = sprintf(
                '%.2f%% boven trigger. %s',
                (float) $position->premarket_gap_pct,
                $description ?? '',
            );
        }

        return [
            'label' => 'Pre-Market',
            'value' => $value,
            'valueColor' => $valueColor,
            'description' => $description,
            'descriptionColor' => $descriptionColor,
            'cardVariant' => $cardVariant,
        ];
    }
}
