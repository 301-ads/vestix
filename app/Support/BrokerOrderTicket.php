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
    public static function forRunnerStopLoss(Position $position): array
    {
        $sl = (float) ($position->runnerStopLossPrice() ?? 0);
        $qty = (float) ($position->runnerQuantity() ?? 0);
        $isShort = $position->isShort();

        return [
            'title' => "{$position->ticker} — Runner Stop-Loss plaatsen",
            'intro' => 'Take Profit is gevuld. IBKR annuleert dan de rest van de bracket, inclusief je stop. Plaats een nieuwe stop-loss voor de runner.',
            'rows' => [
                [
                    'label' => 'Order type',
                    'value' => $isShort ? 'BUY STOP' : 'SELL STOP',
                ],
                [
                    'label' => 'Runner (aantal)',
                    'value' => self::formatQuantity($qty),
                    'copy_value' => self::formatCopyQuantity($qty),
                    'accent' => true,
                ],
                [
                    'label' => 'Stop-Loss (breakeven)',
                    'value' => self::formatMoney($sl),
                    'copy_value' => self::formatCopyMoney($sl),
                    'accent' => true,
                ],
                [
                    'label' => 'Entry',
                    'value' => self::formatMoney((float) ($position->entry_price ?? 0)),
                ],
            ],
            'difference_label' => 'Nieuwe losse stop — de bracket-SL is weg',
            'confirmation' => sprintf(
                'Heb je een nieuwe %s geplaatst op %s voor %s (runner)?',
                $isShort ? 'BUY STOP' : 'SELL STOP',
                self::formatMoney($sl),
                self::formatQuantity($qty),
            ),
            'submit_label' => 'Runner-SL geplaatst',
        ];
    }

    public static function forStopLossUpdate(Position $position): array
    {
        $currentSl = (float) $position->current_sl;
        $newSl = (float) ($position->new_sl ?? 0);
        $difference = $newSl - $currentSl;
        $openQty = (float) ($position->remaining_quantity ?? $position->quantity ?? 0);

        return [
            'title' => "{$position->ticker} — Stop-Loss Update",
            'intro' => null,
            'rows' => [
                [
                    'label' => 'Positie',
                    'value' => self::formatQuantity($openQty),
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
    public static function forTarget1Adjust(Position $position): array
    {
        $needsQty = $position->needsTarget1QtyAdjust();
        $needsPrice = $position->hasPendingTarget1Raise();
        $qty = (float) ($position->target_1_quantity ?? 0);
        $total = (float) ($position->quantity ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);
        $isShort = $position->isShort();
        $current = (float) ($position->storedTarget1LimitPrice() ?? $position->target_1_price ?? 0);
        $pending = (float) ($position->pendingTarget1LimitPrice() ?? 0);
        $rows = [];

        if ($needsQty) {
            $rows[] = [
                'label' => 'Take Profit nu',
                'value' => sprintf('%s (100%%)', self::formatQuantity($total)),
                'tone' => 'old',
            ];
            $rows[] = [
                'label' => 'Take Profit naar',
                'value' => sprintf('%s (%d%%)', self::formatQuantity($qty), $fractionPercent),
                'copy_value' => self::formatCopyQuantity($qty),
                'tone' => 'new',
                'accent' => true,
            ];
        }

        if ($needsPrice) {
            $difference = $pending - $current;
            $rows[] = [
                'label' => 'Fill (entry)',
                'value' => self::formatMoney((float) ($position->entry_price ?? 0)),
            ];
            $rows[] = [
                'label' => 'Huidige Target 1',
                'value' => self::formatMoney($current),
                'tone' => 'old',
            ];
            $rows[] = [
                'label' => 'Nieuwe Target 1',
                'value' => self::formatMoney($pending),
                'copy_value' => self::formatCopyMoney($pending),
                'tone' => 'new',
                'accent' => true,
            ];
            $rows[] = [
                'label' => 'Verschil',
                'value' => sprintf('%s%s', $difference >= 0 ? '+' : '', self::formatMoney($difference)),
                'accent' => true,
            ];
        }

        $intro = match (true) {
            $needsQty && $needsPrice => $isShort
                ? 'TradingView zet TP op 100%. Zet het aantal op 50% en neem de nieuwe Take Profit over — je fill lag onder de sell-stop.'
                : 'TradingView zet TP op 100%. Zet het aantal op 50% en neem de nieuwe Take Profit over — je fill lag boven de buy-stop.',
            $needsQty => 'TradingView zet Take Profit standaard op 100%. Wijzig het TP-aantal naar 50% zodat de runner blijft staan.',
            default => $isShort
                ? 'Fill lag onder je sell-stop. Neem de nieuwe Take Profit 1-op-1 over in je broker.'
                : 'Fill lag boven je buy-stop. Neem de nieuwe Take Profit 1-op-1 over in je broker.',
        };

        $confirmation = match (true) {
            $needsQty && $needsPrice => sprintf(
                'Heb je in je broker het Take Profit-aantal naar %s (%d%%) gezet en de prijs gewijzigd van %s naar %s?',
                self::formatQuantity($qty),
                $fractionPercent,
                self::formatMoney($current),
                self::formatMoney($pending),
            ),
            $needsQty => sprintf(
                'Heb je in je broker het Take Profit-aantal gewijzigd naar %s (%d%%)?',
                self::formatQuantity($qty),
                $fractionPercent,
            ),
            default => sprintf(
                'Heb je de Take Profit in je broker gewijzigd van %s naar %s?',
                self::formatMoney($current),
                self::formatMoney($pending),
            ),
        };

        return [
            'title' => "{$position->ticker} — Take Profit aanpassen",
            'intro' => $intro,
            'rows' => $rows,
            'difference_label' => $needsPrice ? 'R/R 1:2 t.o.v. je fill' : 'TradingView zet TP op 100% tot je het aantal wijzigt',
            'confirmation' => $confirmation,
            'submit_label' => 'Take Profit aangepast',
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
        $entry = $position->advisedEntryStop() ?? (float) ($position->entry_price ?? 0);
        $limitPrice = StopLimitBuffer::limitPriceForDirection($entry, $position->tradeDirection());
        $stopLoss = (float) ($position->new_sl ?? 0);
        $target1 = (float) ($position->copiedBracketTarget1Price() ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);
        $tpQty = (float) ($position->target_1_quantity ?? 0);
        $tpHintPercent = $quantity > 0 ? (int) round(($tpQty / $quantity) * 100) : $fractionPercent;
        $isShort = $position->isShort();
        $throughWarning = $position->isPlannedEntryThroughMarket()
            ? ($isShort
                ? sprintf(
                    'Sell-stop %s ligt boven de koers %s — de setup is al geraakt. Herprijs de signaalkaars; plaats deze stop niet.',
                    self::formatMoney($entry),
                    self::formatMoney((float) $position->latest_close_price),
                )
                : sprintf(
                    'Buy-stop %s ligt onder de koers %s — de setup is al geraakt. Herprijs de signaalkaars; plaats deze stop niet.',
                    self::formatMoney($entry),
                    self::formatMoney((float) $position->latest_close_price),
                ))
            : null;

        if ($isShort) {
            $hardFailReasons = $position->shortSniperHardFailReasons();

            return [
                'title' => "IBKR Bracket Order — {$position->ticker} [SHORT]",
                'intro' => null,
                'warning' => $throughWarning ?? 'LET OP: SHORT POSITIE. Gebruik SELL STOP LIMIT voor de instap.',
                'is_short' => true,
                'sniper_hard_fails' => $hardFailReasons,
                'show_sniper_vision_coming_soon' => true,
                'rows' => [
                    [
                        'label' => 'Order type',
                        'value' => 'SELL STOP LIMIT',
                        'tone' => 'short',
                    ],
                    [
                        'label' => 'Time in Force',
                        'value' => 'Good Till Cancel',
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
                            'TradingView zet TP standaard op 100%%. Plaats eerst de bracket; wijzig daarna het TP-aantal naar %s (%d%%) om te schalen.',
                            self::formatQuantity($tpQty),
                            $tpHintPercent,
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
            'intro' => 'Neem dit exact over in TradingView: vink Take Profit en Stop Loss aan.',
            'warning' => $throughWarning,
            'is_short' => false,
            'sniper_hard_fails' => [],
            'show_sniper_vision_coming_soon' => false,
            'rows' => [
                [
                    'label' => 'Order type',
                    'value' => 'STOP LIMIT (Kopen)',
                ],
                [
                    'label' => 'Time in Force',
                    'value' => 'Good Till Cancel',
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
                        'TradingView zet TP standaard op 100%%. Plaats eerst de bracket; wijzig daarna het TP-aantal naar %s (%d%%) om te schalen.',
                        self::formatQuantity($tpQty),
                        $tpHintPercent,
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
