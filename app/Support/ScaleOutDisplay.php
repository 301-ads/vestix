<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Support\HtmlString;

class ScaleOutDisplay
{
    public static function orderPlanHtml(Position $position): HtmlString
    {
        $html = '<div class="vestix-order-plan space-y-4 text-sm">';
        $html .= self::summaryBlock($position);
        $html .= '<ol class="vestix-order-plan__steps">';
        $html .= self::stepOne($position);
        $html .= self::stepTwo($position);
        $html .= '</ol>';
        $html .= '</div>';

        return new HtmlString($html);
    }

    private static function summaryBlock(Position $position): string
    {
        $entry = $position->entry_price;
        $qty = $position->quantity;
        $investment = $position->investment;
        $riskStop = $position->initial_sl ?? $position->current_sl;

        $rows = '';

        if ($qty !== null && $investment > 0) {
            $rows .= self::summaryRow('Inleg', '$'.number_format($investment, 2).' ('.self::formatQty((float) $qty).' stuks)');
        }

        // Alleen een échte downside (stop onder entry) telt als trade-risico.
        // Is de stop op/boven entry opgetrokken, dan is het risico afgedekt.
        $hasDownsideRisk = $riskStop !== null
            && $entry !== null
            && (float) $entry > 0
            && (float) $riskStop < (float) $entry;

        if ($entry !== null && (float) $entry > 0 && $riskStop !== null) {
            if ($hasDownsideRisk) {
                $riskPct = (((float) $entry - (float) $riskStop) / (float) $entry) * 100;
                $rows .= self::summaryRow('Trade Risico (Stop)', self::formatSignedPercent(-$riskPct));
            } else {
                $rows .= self::summaryRow('Trade Risico (Stop)', 'Risico afgedekt');
            }
        }

        $user = $position->user ?? auth()->user();
        $bankroll = $user?->trading_bankroll !== null ? (float) $user->trading_bankroll : null;

        if ($hasDownsideRisk && $qty !== null && $bankroll !== null && $bankroll > 0) {
            $plannedRisk = ((float) $entry - (float) $riskStop) * (float) $qty;
            $accountImpact = ($plannedRisk / $bankroll) * 100;
            $rows .= self::summaryRow('Account Impact', self::formatSignedPercent(-$accountImpact, 1).' bankroll');
        }

        return '<div class="vestix-order-plan__summary space-y-1">'
            .'<p class="font-semibold text-gray-950 dark:text-white">Gepland Risico &amp; Executie</p>'
            .'<dl class="space-y-1">'.$rows.'</dl>'
            .'</div>';
    }

    private static function summaryRow(string $label, string $value): string
    {
        return '<div class="flex items-baseline justify-between gap-4">'
            .'<dt class="text-gray-500 dark:text-gray-400">'.e($label).'</dt>'
            .'<dd class="font-medium text-gray-900 dark:text-gray-100 text-right">'.$value.'</dd>'
            .'</div>';
    }

    private static function stepOne(Position $position): string
    {
        if ($position->hasScaledOut()) {
            $date = $position->scaled_out_at?->locale('nl')->isoFormat('D MMM Y') ?? '—';
            $body = '<p class="font-semibold text-success-600 dark:text-success-400">Target 1 uitgevoerd</p>'
                .'<p class="text-gray-600 dark:text-gray-300">Uitgevoerd op '.$date.' voor $'
                .number_format((float) $position->scaled_out_price, 2).'. <span class="font-medium text-success-600 dark:text-success-400">+$'
                .number_format((float) $position->realized_pnl, 2).' veiliggesteld.</span></p>'
                .'<p class="text-gray-500 dark:text-gray-400">Stop verplaatst naar breakeven.</p>';

            return self::stepRow(self::checkMarker('success'), $body, hasLine: true);
        }

        $fractionPct = (int) round($position->effective_first_tranche_fraction * 100);
        $body = '<p class="font-semibold text-gray-950 dark:text-white">Target 1 &middot; Verkoop '.$fractionPct.'%</p>';

        $details = [];

        if ($position->target_1_price !== null) {
            $details[] = 'Prijs <span class="font-semibold text-gray-900 dark:text-gray-100">$'
                .number_format((float) $position->target_1_price, 2).'</span> (1:'
                .rtrim(rtrim(number_format($position->effective_target_1_rr, 1), '0'), '.').' R/R)';
        }

        if ($position->target_1_profit_dollars !== null) {
            $details[] = 'Winst <span class="font-semibold text-success-600 dark:text-success-400">+$'
                .number_format((float) $position->target_1_profit_dollars, 2).'</span> (dekt risico af)';
        }

        if ($position->target_1_quantity !== null) {
            $details[] = 'Limit sell '.self::formatQty((float) $position->target_1_quantity).' stuks';
        }

        foreach ($details as $detail) {
            $body .= '<p class="text-gray-600 dark:text-gray-300">'.$detail.'</p>';
        }

        $canLogScaleOut = $position->status === 'open'
            && ! $position->hasScaledOut()
            && ($position->isTarget1Hit() || $position->hasTarget1LimitPlaced());

        if ($canLogScaleOut) {
            $body .= '<div class="vestix-order-plan__step-one-action"></div>';
        }

        return self::stepRow(self::numberMarker('1', 'primary'), $body, hasLine: true);
    }

    private static function stepTwo(Position $position): string
    {
        $variant = $position->hasScaledOut() ? 'primary' : 'muted';

        $body = '<p class="font-semibold text-gray-950 dark:text-white">Target 2 &middot; De Runner</p>'
            .'<p class="text-gray-600 dark:text-gray-300">Trailing stop onder de dagelijkse SMA 20.</p>';

        return self::stepRow(self::numberMarker('2', $variant), $body, hasLine: false);
    }

    private static function stepRow(string $marker, string $body, bool $hasLine): string
    {
        $line = $hasLine
            ? '<span class="mt-1 w-px flex-1 bg-gray-200 dark:bg-gray-700"></span>'
            : '';

        $bodyPadding = $hasLine ? ' pb-4' : '';

        return '<li class="flex gap-3">'
            .'<div class="flex flex-col items-center">'.$marker.$line.'</div>'
            .'<div class="flex-1 space-y-0.5'.$bodyPadding.'">'.$body.'</div>'
            .'</li>';
    }

    private static function numberMarker(string $number, string $variant): string
    {
        $classes = match ($variant) {
            'primary' => 'bg-primary-500 text-white',
            'success' => 'bg-success-500 text-white',
            default => 'border border-gray-300 text-gray-500 dark:border-gray-600 dark:text-gray-400',
        };

        return '<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold '.$classes.'">'.$number.'</span>';
    }

    private static function checkMarker(string $variant): string
    {
        $classes = $variant === 'success' ? 'bg-success-500 text-white' : 'bg-primary-500 text-white';

        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">'
            .'<path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd" />'
            .'</svg>';

        return '<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full '.$classes.'">'.$icon.'</span>';
    }

    private static function formatSignedPercent(float $value, int $decimals = 2): string
    {
        $sign = $value > 0 ? '+' : ($value < 0 ? '-' : '');
        $number = rtrim(rtrim(number_format(abs($value), $decimals), '0'), '.');

        return $sign.$number.'%';
    }

    private static function formatQty(float $qty): string
    {
        return floor($qty) === $qty
            ? number_format($qty, 0)
            : rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
