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

    public static function smartAlertContent(Position $position): HtmlString
    {
        $daysUntil = (int) $position->daysUntilEarnings();
        $earningsDate = $position->effectiveEarningsDate();
        $hour = $position->asset?->effectiveEarningsHour() ?? EarningsReleaseHour::Unknown;

        $isDanger = $daysUntil <= self::DANGER_THRESHOLD_DAYS
            || in_array($position->earningsExitUrgency(), [EarningsExitUrgency::ExitToday, EarningsExitUrgency::Overdue], true);

        $colorClass = $isDanger
            ? 'text-rose-500 bg-rose-500/10 border-rose-500/20'
            : 'text-amber-500 bg-amber-500/10 border-amber-500/20';
        $iconColor = $isDanger ? 'text-rose-500' : 'text-amber-500';

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
            ? EarningsExitSchedule::exitDeadline($earningsDate)->locale('nl')->isoFormat('ddd D MMM')
            : null;

        $subtitle = $exitDeadline !== null
            ? "Verwacht op {$dateString} — {$timeString}. Sluit uiterlijk {$exitDeadline} vóór slotbel."
            : "Verwacht op {$dateString} — {$timeString}.";

        return new HtmlString(
            '<div class="flex items-center gap-3 px-4 py-3 rounded-xl border '.$colorClass.'">'
            .'<svg class="w-5 h-5 '.$iconColor.' shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">'
            .'<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />'
            .'</svg>'
            .'<div class="flex flex-col">'
            .'<span class="font-bold text-sm tracking-tight">Let op: Earnings report over '.$daysLabel.'!</span>'
            .'<span class="text-xs opacity-80 font-medium">'.e($subtitle).'</span>'
            .'</div>'
            .'</div>'
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
        $exitDeadline = EarningsExitSchedule::exitDeadline($earningsDate);

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

        if (StopLossProtocol::isPreEarningsWindow($position)) {
            $trailingLabel = StopLossProtocol::activeMode($position) === TrailingStopMode::AggressivePreEarnings
                ? 'Agressief trailing ('.StopLossProtocol::aggressiveFormulaLabel().')'
                : 'Standaard trailing';
            $description .= ' · Pre-earnings modus: '.$trailingLabel;
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
            'cardVariant' => $cardVariant,
        ];
    }
}
