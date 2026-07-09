<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Support\HtmlString;

class BrokerOrderTicket
{
    /**
     * @return array{
     *     title: string,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string}>,
     *     difference_label: string|null,
     *     confirmation: string,
     *     submit_label: string,
     * }
     */
    public static function forStopLossUpdate(Position $position): array
    {
        $currentSl = (float) $position->current_sl;
        $newSl = (float) ($position->new_sl ?? 0);
        $difference = $newSl - $currentSl;

        return [
            'title' => "{$position->ticker} — Stop-Loss Update",
            'rows' => [
                [
                    'label' => 'Positie',
                    'value' => self::formatQuantity((float) ($position->quantity ?? 0)),
                ],
                [
                    'label' => 'Oude Stop-Loss',
                    'value' => self::formatMoney($currentSl),
                    'tone' => 'old',
                ],
                [
                    'label' => 'Nieuwe Stop-Loss',
                    'value' => self::formatMoney($newSl),
                    'tone' => 'new',
                ],
                [
                    'label' => 'Verschil',
                    'value' => sprintf('%s%s', $difference >= 0 ? '+' : '', self::formatMoney($difference)),
                    'accent' => true,
                ],
            ],
            'difference_label' => 'Winst/Risico gereduceerd',
            'confirmation' => sprintf(
                'Heb je de Stop-Loss order in je broker (bijv. Lynx/IBKR) succesvol gewijzigd naar %s?',
                self::formatMoney($newSl),
            ),
            'submit_label' => 'Stop-Loss Updated',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string}>,
     *     difference_label: string|null,
     *     confirmation: string,
     *     submit_label: string,
     * }
     */
    public static function forLimitSell(Position $position): array
    {
        if ($position->userUsesRevolutWorkflow()) {
            return self::forRevolutTarget1Hit($position);
        }

        $limitPrice = (float) ($position->target_1_price ?? 0);
        $sellQty = (float) ($position->target_1_quantity ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);

        return [
            'title' => "{$position->ticker} — Limit Sell",
            'rows' => [
                [
                    'label' => 'Totale positie',
                    'value' => self::formatQuantity((float) ($position->quantity ?? 0)),
                ],
                [
                    'label' => 'Te verkopen',
                    'value' => sprintf(
                        '%s (%d%%)',
                        self::formatQuantity($sellQty),
                        $fractionPercent,
                    ),
                ],
                [
                    'label' => 'Limit prijs',
                    'value' => self::formatMoney($limitPrice),
                    'accent' => true,
                ],
                [
                    'label' => 'Huidige Stop-Loss',
                    'value' => self::formatMoney((float) ($position->current_sl ?? 0)),
                ],
            ],
            'difference_label' => null,
            'confirmation' => sprintf(
                'Heb je de Limit Sell order in je broker geplaatst op %s voor %s (%d%% van je positie)?',
                self::formatMoney($limitPrice),
                self::formatQuantity($sellQty),
                $fractionPercent,
            ),
            'submit_label' => 'Confirm Limit Sell',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string}>,
     *     difference_label: string|null,
     *     confirmation: string,
     *     submit_label: string,
     * }
     */
    private static function forRevolutTarget1Hit(Position $position): array
    {
        $targetPrice = (float) ($position->target_1_price ?? 0);
        $sellQty = (float) ($position->target_1_quantity ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);

        return [
            'title' => "{$position->ticker} — Target 1 bereikt",
            'rows' => [
                [
                    'label' => 'Totale positie',
                    'value' => self::formatQuantity((float) ($position->quantity ?? 0)),
                ],
                [
                    'label' => 'Te verkopen',
                    'value' => sprintf(
                        '%s (%d%%)',
                        self::formatQuantity($sellQty),
                        $fractionPercent,
                    ),
                ],
                [
                    'label' => 'Target prijs',
                    'value' => self::formatMoney($targetPrice),
                    'accent' => true,
                ],
                [
                    'label' => 'Huidige Stop-Loss',
                    'value' => self::formatMoney((float) ($position->current_sl ?? 0)),
                ],
            ],
            'difference_label' => null,
            'confirmation' => sprintf(
                'Heb je Target 1 op %s gezien (Telegram of Revolut-notificatie) en ben je klaar om %s (%d%%) handmatig te verkopen?',
                self::formatMoney($targetPrice),
                self::formatQuantity($sellQty),
                $fractionPercent,
            ),
            'submit_label' => 'Target 1 bevestigd',
        ];
    }

    public static function modalIcon(Position $position): HtmlString
    {
        $position->loadMissing('asset');

        return new HtmlString(
            view('filament.positions.broker-order-ticket-modal-icon', [
                'ticker' => $position->ticker,
                'iconUrl' => $position->asset?->icon_url,
            ])->render()
        );
    }

    public static function formatQuantity(float $quantity): string
    {
        $formatted = rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');

        return "{$formatted} stuks";
    }

    public static function formatMoney(float $value): string
    {
        return '$'.number_format($value, 2);
    }
}
