<?php

namespace App\Support;

use App\Enums\PremarketScanResult;
use App\Models\Position;

class PremarketGatekeeperDisplay
{
    public static function isRelevant(Position $position): bool
    {
        return $position->wasPremarketCheckedToday();
    }

    public static function scanTypeLabel(Position $position): ?string
    {
        if (! self::isRelevant($position) || $position->premarket_scan_type === null) {
            return null;
        }

        return $position->premarket_scan_type->label();
    }

    public static function scanTypeColor(Position $position): string
    {
        if ($position->premarket_scan_type instanceof PremarketScanResult) {
            return $position->premarket_scan_type->badgeColor();
        }

        return 'gray';
    }

    public static function rowClass(Position $position): ?string
    {
        if ($position->hasExecutionDigestCancellation()) {
            return 'scout-execution-cancelled';
        }

        if ($position->hasPremarketGapUpRisk()) {
            return 'scout-premarket-gap-up';
        }

        if ($position->hasPremarketReclamation()) {
            return 'scout-premarket-reclamation';
        }

        if ($position->hasPremarketLanding()) {
            return 'scout-premarket-landing';
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

        $result = $position->premarket_scan_type;
        $value = $position->premarket_price !== null
            ? '$'.number_format((float) $position->premarket_price, 2)
            : '—';

        $description = null;
        $descriptionColor = 'gray';
        $valueColor = null;
        $cardVariant = 'blue';

        if ($result === PremarketScanResult::GapRisk) {
            $reference = $position->premarket_reference_price ?? $position->signal_high;
            $description = sprintf(
                'Boven bounce high ($%s). Risico op chasing!',
                number_format((float) $reference, 2),
            );
            $descriptionColor = 'danger';
            $valueColor = 'danger';
            $cardVariant = 'amber';
        } elseif ($result === PremarketScanResult::Reclamation) {
            $reference = $position->premarket_reference_price ?? $position->latest_sma_20;
            $description = sprintf(
                'Herovert SMA 20 ($%s). Potentiële intraday setup.',
                number_format((float) $reference, 2),
            );
            $descriptionColor = 'success';
            $valueColor = 'success';
            $cardVariant = 'green';
        } elseif ($result === PremarketScanResult::Landing) {
            $reference = $position->premarket_reference_price ?? $position->latest_sma_20;
            $description = sprintf(
                'Nadert SMA 20 ($%s). Potentiële landing.',
                number_format((float) $reference, 2),
            );
            $descriptionColor = 'warning';
            $valueColor = 'warning';
            $cardVariant = 'amber';
        } elseif ($result === PremarketScanResult::Ok) {
            $description = 'Geen pre-market signaal vandaag.';
            $descriptionColor = 'success';
            $valueColor = 'success';
        } elseif ($result === PremarketScanResult::Unavailable) {
            $description = 'Geen live quote beschikbaar.';
            $descriptionColor = 'gray';
        }

        if ($position->premarket_distance_pct !== null && $result === PremarketScanResult::GapRisk) {
            $description = sprintf(
                '%.2f%% boven bounce high. %s',
                (float) $position->premarket_distance_pct,
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
