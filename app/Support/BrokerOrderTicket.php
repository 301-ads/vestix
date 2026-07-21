<?php

namespace App\Support;

use App\Models\Position;
use Illuminate\Support\HtmlString;

class BrokerOrderTicket
{
    /**
     * @return array{
     *     title: string,
     *     intro: string|null,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string, copy_value?: string, hint?: string}>,
     *     difference_label: string|null,
     *     confirmation: string,
     *     submit_label: string,
     * }
     */
    public static function forInitialStopLoss(Position $position): array
    {
        $sl = (float) ($position->current_sl ?? 0);

        return [
            'title' => "{$position->ticker} — Stop-Loss plaatsen",
            'intro' => null,
            'rows' => [
                [
                    'label' => 'Positie',
                    'value' => self::formatQuantity((float) ($position->quantity ?? 0)),
                ],
                [
                    'label' => 'Entry prijs',
                    'value' => self::formatMoney((float) ($position->entry_price ?? 0)),
                ],
                [
                    'label' => 'Stop-Loss',
                    'value' => self::formatMoney($sl),
                    'accent' => true,
                ],
            ],
            'difference_label' => null,
            'confirmation' => sprintf(
                'Heb je de Stop-Loss order in je broker (bijv. Lynx/IBKR) geplaatst op %s?',
                self::formatMoney($sl),
            ),
            'submit_label' => 'Stop-Loss geplaatst',
        ];
    }

    public static function forStopLossUpdate(Position $position): array
    {
        $currentSl = (float) $position->current_sl;
        $newSl = (float) ($position->new_sl ?? 0);
        $difference = $newSl - $currentSl;

        return [
            'title' => "{$position->ticker} — Stop-Loss Update",
            'intro' => null,
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
                    'copy_value' => self::formatCopyMoney($newSl),
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
     *     intro: string|null,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string, copy_value?: string, hint?: string}>,
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
            'intro' => null,
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
     *     intro: string|null,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string, copy_value?: string, hint?: string}>,
     *     difference_label: string|null,
     *     confirmation: string,
     *     submit_label: string,
     * }
     */
    public static function forIbkrBracket(Position $position): array
    {
        $quantity = (float) ($position->quantity ?? 0);
        $entry = (float) ($position->entry_price ?? 0);
        $limitPrice = StopLimitBuffer::limitPriceForDirection($entry, $position->tradeDirection());
        $stopLoss = (float) ($position->new_sl ?? 0);
        $target1 = (float) ($position->plannedBracketTarget1Price() ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);
        $tpQty = (float) ($position->target_1_quantity ?? 0);
        $isShort = $position->isShort();

        if ($isShort) {
            return [
                'title' => "IBKR Bracket Order — {$position->ticker} [SHORT]",
                'intro' => 'LET OP: SHORT POSITIE. Gebruik SELL STOP LIMIT voor de instap. Time in Force = GTC; vink Take Profit (BUY LIMIT) en Stop Loss (BUY STOP) aan.',
                'warning' => 'LET OP: SHORT POSITIE. Gebruik SELL STOP LIMIT voor de instap.',
                'is_short' => true,
                'rows' => [
                    [
                        'label' => 'Order type',
                        'value' => 'SELL STOP LIMIT',
                        'tone' => 'short',
                    ],
                    [
                        'label' => 'Aantal (Quantity)',
                        'value' => self::formatQuantity($quantity),
                        'copy_value' => self::formatCopyQuantity($quantity),
                    ],
                    [
                        'label' => 'Prijs (Sell-Stop)',
                        'value' => self::formatMoney($entry),
                        'copy_value' => self::formatCopyMoney($entry),
                        'accent' => true,
                    ],
                    [
                        'label' => 'Limit Prijs (Min Verkoop)',
                        'value' => self::formatMoney($limitPrice),
                        'copy_value' => self::formatCopyMoney($limitPrice),
                        'accent' => true,
                    ],
                    [
                        'label' => 'Take Profit (BUY LIMIT)',
                        'value' => self::formatMoney($target1),
                        'copy_value' => self::formatCopyMoney($target1),
                        'hint' => sprintf(
                            'TradingView zet TP standaard op 100%%. Plaats eerst de bracket; wijzig daarna het TP-aantal naar %s (%d%%) om te schalen. Verlaag vervolgens de SL-qty naar het restant.',
                            self::formatQuantity($tpQty),
                            $fractionPercent,
                        ),
                    ],
                    [
                        'label' => 'Stop Loss (BUY STOP)',
                        'value' => self::formatMoney($stopLoss),
                        'copy_value' => self::formatCopyMoney($stopLoss),
                    ],
                ],
                'difference_label' => null,
                'confirmation' => 'Heb je de SHORT bracket order (SELL STOP LIMIT) in TradingView/IBKR verzonden?',
                'submit_label' => 'Order geplaatst',
            ];
        }

        return [
            'title' => "IBKR Bracket Order — {$position->ticker}",
            'intro' => 'Neem dit exact over in TradingView: Order Type = STOP LIMIT (Kopen), Time in Force = GTC, vink Take Profit en Stop Loss aan.',
            'warning' => null,
            'is_short' => false,
            'rows' => [
                [
                    'label' => 'Order type',
                    'value' => 'STOP LIMIT (Kopen)',
                ],
                [
                    'label' => 'Aantal (Quantity)',
                    'value' => self::formatQuantity($quantity),
                    'copy_value' => self::formatCopyQuantity($quantity),
                ],
                [
                    'label' => 'Prijs (Buy-Stop)',
                    'value' => self::formatMoney($entry),
                    'copy_value' => self::formatCopyMoney($entry),
                    'accent' => true,
                ],
                [
                    'label' => 'Limit Prijs (Max Inkoop)',
                    'value' => self::formatMoney($limitPrice),
                    'copy_value' => self::formatCopyMoney($limitPrice),
                    'accent' => true,
                ],
                [
                    'label' => 'Take Profit (Target 1)',
                    'value' => self::formatMoney($target1),
                    'copy_value' => self::formatCopyMoney($target1),
                    'hint' => sprintf(
                        'TradingView zet TP standaard op 100%%. Plaats eerst de bracket; wijzig daarna het TP-aantal naar %s (%d%%) om te schalen. Verlaag vervolgens de SL-qty naar het restant.',
                        self::formatQuantity($tpQty),
                        $fractionPercent,
                    ),
                ],
                [
                    'label' => 'Stop Loss',
                    'value' => self::formatMoney($stopLoss),
                    'copy_value' => self::formatCopyMoney($stopLoss),
                ],
            ],
            'difference_label' => null,
            'confirmation' => 'Heb je de bracket order in TradingView/IBKR verzonden?',
            'submit_label' => 'Order geplaatst',
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     intro: string|null,
     *     rows: list<array{label: string, value: string, accent?: bool, tone?: string, copy_value?: string, hint?: string}>,
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
            'intro' => null,
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

    public static function formatCopyQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
    }

    public static function formatCopyMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
