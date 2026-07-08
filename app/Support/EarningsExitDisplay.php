<?php

namespace App\Support;

use App\Enums\EarningsExitUrgency;
use App\Enums\EarningsReleaseHour;
use App\Enums\TrailingStopMode;
use App\Models\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class EarningsExitDisplay
{
    public const ALERT_WINDOW_DAYS = 14;

    public const DANGER_THRESHOLD_DAYS = 3;

    public static function isWithinAlertWindow(Position $position, int $windowDays = self::ALERT_WINDOW_DAYS): bool
    {
        $daysUntil = $position->daysUntilEarnings();

        return $daysUntil !== null && $daysUntil >= 0 && $daysUntil <= $windowDays;
    }

    public static function isRelevant(Position $position): bool
    {
        if ($position->status !== 'open') {
            return false;
        }

        $earningsDate = $position->effectiveEarningsDate();

        if ($earningsDate === null) {
            return false;
        }

        return $earningsDate->greaterThanOrEqualTo(Carbon::today('Europe/Amsterdam')->startOfDay())
            && self::isWithinAlertWindow($position);
    }

    public static function sectionDaysBadgeLabel(?Position $position): ?string
    {
        if ($position === null) {
            return null;
        }

        $position->loadMissing('asset');

        if ($position->effectiveEarningsDate() === null) {
            return null;
        }

        $daysUntil = $position->daysUntilEarnings();

        if ($daysUntil === null) {
            return null;
        }

        return match (true) {
            $daysUntil === 0 => 'Vandaag',
            $daysUntil === 1 => 'Over 1 dag',
            default => "Over {$daysUntil} dagen",
        };
    }

    public static function sectionDaysBadgeColor(?Position $position): string
    {
        if ($position === null) {
            return 'gray';
        }

        $daysUntil = $position->daysUntilEarnings();

        if ($daysUntil === null) {
            return 'gray';
        }

        if ($daysUntil <= self::DANGER_THRESHOLD_DAYS || $position->requiresEarningsExit()) {
            return 'danger';
        }

        if ($daysUntil <= self::ALERT_WINDOW_DAYS) {
            return 'warning';
        }

        return 'gray';
    }

    public static function isSmartAlertVisible(?Position $position, string $operation): bool
    {
        if ($operation !== 'edit' || $position === null || $position->status !== 'open') {
            return false;
        }

        return $position->effectiveEarningsDate() !== null
            && self::isWithinAlertWindow($position);
    }

    public static function smartAlertViewData(Position $position): array
    {
        $daysUntil = (int) $position->daysUntilEarnings();
        $earningsDate = $position->effectiveEarningsDate();
        $hour = $position->asset?->effectiveEarningsHour() ?? EarningsReleaseHour::Unknown;

        $isDanger = $daysUntil <= self::DANGER_THRESHOLD_DAYS
            || in_array($position->earningsExitUrgency(), [EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue], true);

        $daysLabel = match (true) {
            $daysUntil === 0 => 'vandaag',
            $daysUntil === 1 => '1 dag',
            default => $daysUntil.' dagen',
        };

        $dateString = $earningsDate?->locale('nl')->isoFormat('D MMM Y') ?? '—';
        $timeString = match ($hour) {
            EarningsReleaseHour::Bmo => 'Voor beurs (BMO)',
            EarningsReleaseHour::Amc => 'Na beurs (AMC)',
            default => 'Tijd onbekend',
        };

        $exitDeadline = $earningsDate !== null
            ? EarningsExitSchedule::exitDeadline($earningsDate, $hour)->locale('nl')->isoFormat('ddd D MMM')
            : null;

        $subtitle = $exitDeadline !== null
            ? "Verwacht op {$dateString} — {$timeString}. Sluit uiterlijk {$exitDeadline} vóór slotbel."
            : "Verwacht op {$dateString} — {$timeString}.";

        $trailingNote = null;

        if (StopLossProtocol::isPreEarningsWindow($position)) {
            $trailingNote = match (StopLossProtocol::activeMode($position)) {
                TrailingStopMode::AggressivePreEarnings => 'Pre-earnings escalatie ('.StopLossProtocol::aggressiveFormulaLabel().')',
                TrailingStopMode::AggressiveOverbought => 'Oververhit: agressief ATR ('.StopLossProtocol::overboughtFormulaLabel().')',
                default => 'Pre-earnings: standaard trailing',
            };
        } elseif (StopLossProtocol::isRsiOverbought($position)) {
            $trailingNote = 'Oververhit: agressief ATR ('.StopLossProtocol::overboughtFormulaLabel().')';
        }

        return [
            'daysLabel' => $daysLabel,
            'subtitle' => $subtitle,
            'trailingNote' => $trailingNote,
            'isDanger' => $isDanger,
        ];
    }

    public static function smartAlertContent(Position $position): HtmlString
    {
        return new HtmlString(
            view('filament.positions.earnings-smart-alert', self::smartAlertViewData($position))->render(),
        );
    }

    public static function syncStatusContent(?Position $position): HtmlString
    {
        if ($position === null) {
            return new HtmlString('<span class="text-sm text-gray-500">Geen positie geladen.</span>');
        }

        $position->loadMissing('asset');
        $asset = $position->asset;

        if ($asset === null) {
            return new HtmlString('<span class="text-sm text-gray-500">Nog geen asset gekoppeld.</span>');
        }

        if ($asset->earnings_fetched_at === null) {
            return new HtmlString(
                '<span class="text-sm text-gray-500">Nog niet opgehaald. Sync marktdata om earnings te laden.</span>'
            );
        }

        $effectiveDate = $asset->effectiveEarningsDate();

        if ($effectiveDate === null) {
            return new HtmlString(
                '<span class="text-sm text-gray-500">Geen aankomende earnings gevonden in Finnhub'
                .' (laatst gecheckt '.$asset->earnings_fetched_at->diffForHumans().').</span>'
            );
        }

        $hour = $asset->effectiveEarningsHour()->label();
        $source = $asset->earnings_date_override !== null ? 'handmatige override' : 'Finnhub';

        return new HtmlString(
            '<div class="text-sm leading-relaxed">'
            .'<span class="font-semibold text-emerald-400">'
            .e($effectiveDate->locale('nl')->isoFormat('D MMM Y')).' — '.e($hour)
            .'</span>'
            .'<br><span class="text-xs text-gray-500">Bron: '.e($source)
            .' · sync '.e($asset->earnings_fetched_at->diffForHumans()).'</span>'
            .'</div>'
        );
    }

    /**
     * @return array{
     *     label: string,
     *     value: string,
     *     valueColor: string|null,
     *     description: string|null,
     *     descriptionColor: string,
     *     descriptionWrap: bool,
     *     secondaryDescription: array{text: string, color?: string, icon?: string|null, tooltip?: string|null}|null,
     *     cardVariant: string,
     * }|null
     */
    public static function cockpitCardData(Position $position): ?array
    {
        if (! self::isRelevant($position)) {
            return null;
        }

        $earningsDate = $position->effectiveEarningsDate();
        $daysUntil = $position->daysUntilEarnings();
        $hour = $position->asset?->effectiveEarningsHour();
        $urgency = $position->earningsExitUrgency();
        $exitDeadline = EarningsExitSchedule::exitDeadline($earningsDate, $hour);

        $value = match (true) {
            $daysUntil === 0 => 'Vandaag',
            $daysUntil === 1 => 'Morgen',
            default => $daysUntil.' dagen',
        };

        $hourLabel = $hour?->label() ?? 'Onbekend';
        $deadlineLabel = $exitDeadline->locale('nl')->isoFormat('ddd D MMM');
        $description = sprintf(
            '%s — sluit uiterlijk %s vóór slotbel',
            $hourLabel,
            $deadlineLabel,
        );

        $secondaryDescription = null;

        if (StopLossProtocol::isPreEarningsWindow($position)) {
            $mode = StopLossProtocol::activeMode($position);
            $secondaryDescription = match ($mode) {
                TrailingStopMode::AggressivePreEarnings => [
                    'text' => 'Pre-earnings escalatie',
                    'color' => 'danger',
                    'icon' => 'heroicon-m-exclamation-triangle',
                    'tooltip' => 'Strengste trailing actief ('.StopLossProtocol::aggressiveFormulaLabel().')',
                ],
                TrailingStopMode::AggressiveOverbought => [
                    'text' => 'Oververhit: agressief SL',
                    'color' => 'warning',
                    'icon' => 'heroicon-m-bolt',
                    'tooltip' => 'Agressief ATR-trailing actief ('.StopLossProtocol::overboughtFormulaLabel().')',
                ],
                default => [
                    'text' => 'Pre-earnings: standaard SL',
                    'color' => 'gray',
                    'icon' => null,
                    'tooltip' => 'Nog niet oververhit — standaard trailing (SMA20 − 0,5×ATR).',
                ],
            };
        }

        $descriptionColor = 'gray';
        $valueColor = null;
        $cardVariant = 'zinc';

        if ($urgency === EarningsExitUrgency::Prepare) {
            $descriptionColor = 'warning';
            $cardVariant = 'amber';
        } elseif (in_array($urgency, [EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue], true)) {
            $descriptionColor = 'danger';
            $valueColor = 'danger';
            $cardVariant = 'danger';
        } elseif ($daysUntil !== null && $daysUntil <= 7) {
            $cardVariant = 'amber';
        }

        return [
            'label' => 'Earnings',
            'value' => $value,
            'valueColor' => $valueColor,
            'description' => $description,
            'descriptionColor' => $descriptionColor,
            'descriptionWrap' => true,
            'secondaryDescription' => $secondaryDescription,
            'cardVariant' => $cardVariant,
        ];
    }
}
