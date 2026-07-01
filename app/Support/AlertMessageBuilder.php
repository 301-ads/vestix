<?php

namespace App\Support;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Enums\EarningsExitUrgency;
use App\Enums\TrailingStopMode;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\User;

class AlertMessageBuilder
{
    public static function forEvent(AlertEventType $event, Position $position, array $context = []): string
    {
        $loginUrl = url('/admin');

        return match ($event) {
            AlertEventType::SlCanRaise => sprintf(
                '<b>%s</b>: stop-loss kan wiskundig verhoogd worden naar $%s%s. <a href="%s">Inloggen</a>',
                e($position->ticker),
                number_format((float) ($context['new_sl'] ?? $position->new_sl), 2),
                self::trailingModeSuffix($position),
                $loginUrl,
            ),
            AlertEventType::FreerideSecured => sprintf(
                '<b>Freeride secured</b> op %s (+%s%%). Risico = $0. <a href="%s">Bekijk positie</a>',
                e($position->ticker),
                number_format($position->unrealized_pnl_percentage, 2),
                $loginUrl,
            ),
            AlertEventType::StoppedOut => sprintf(
                '<b>%s</b>: STOPPED OUT bij $%s.',
                e($position->ticker),
                number_format((float) $position->latest_close_price, 2),
            ),
            AlertEventType::SquadCopyAlert => sprintf(
                '<b>Squad radar</b>: %s heeft %s %s.',
                e($context['actor_name'] ?? 'Een squad-lid'),
                e($context['action'] ?? 'een trade uitgevoerd op'),
                e($position->ticker),
            ),
            AlertEventType::DailyDigest => $context['digest_body'] ?? 'Geen actiepunten vandaag.',
            AlertEventType::PremarketGapRisk => sprintf(
                '<b>WAARSCHUWING:</b> Pas op, risico op chasing bij %s! Pre-market noteert $%s (%.2f%% boven bounce high $%s). <a href="%s">Open setup</a>',
                e($position->ticker),
                number_format((float) ($context['premarket_price'] ?? $position->premarket_price ?? 0), 2),
                number_format((float) ($context['gap_pct'] ?? $position->premarket_distance_pct ?? 0), 2),
                number_format((float) ($context['bounce_high'] ?? $position->premarket_reference_price ?? $position->signal_high ?? 0), 2),
                ScoutResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::PremarketReclamation => sprintf(
                '<b>Kopers actief!</b> %s herovert SMA 20 pre-market ($%s). Potentiële intraday setup. <a href="%s">Open setup</a>',
                e($position->ticker),
                number_format((float) ($context['premarket_price'] ?? $position->premarket_price ?? 0), 2),
                ScoutResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::PremarketLanding => sprintf(
                '<b>Landing nadert:</b> %s noteert $%s pre-market (%.2f%% onder SMA 20 $%s). Potentiële landing. <a href="%s">Open setup</a>',
                e($position->ticker),
                number_format((float) ($context['premarket_price'] ?? $position->premarket_price ?? 0), 2),
                number_format((float) ($context['distance_pct'] ?? $position->premarket_distance_pct ?? 0), 2),
                number_format((float) ($context['sma_20'] ?? $position->premarket_reference_price ?? $position->latest_sma_20 ?? 0), 2),
                ScoutResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::EarningsWarning => sprintf(
                '⚠️ <b>EARNINGS WARNING:</b> %s publiceert over 2 dagen cijfers. Controleer je huidige winst/verlies en bereid de exit voor morgen voor. <a href="%s">Open positie</a>',
                e($position->ticker),
                PositionResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::EarningsActionRequired => sprintf(
                '🚨 <b>EARNINGS ACTION REQUIRED:</b> %s publiceert morgen cijfers. Sluit vandaag alle posities en annuleer openstaande orders vóór de slotbel (22:00 uur). <a href="%s">Open positie</a>',
                e($position->ticker),
                PositionResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::Overbought => sprintf(
                '<b>OVERBOUGHT ALERT:</b> %s RSI %s — agressief trailing actief (%s). <a href="%s">Open positie → Update SL</a>',
                e($position->ticker),
                number_format((float) ($context['rsi'] ?? $position->scout_rsi ?? 0), 1),
                e(StopLossProtocol::overboughtFormulaLabel()),
                PositionResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::MarketOpenBuyStopReminder => self::marketOpenBuyStopReminder($position, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function marketOpenBuyStopReminder(Position $position, array $context): string
    {
        $user = $context['user'] ?? $position->user;
        $broker = $user instanceof User ? $user->primary_broker : null;

        if ($broker === null) {
            $broker = Broker::Revolut;
        }

        $lines = [
            sprintf('🎯 <b>BUY-STOP REMINDER: %s</b>', e($position->ticker)),
            sprintf('Entry (stop): $%s', number_format((float) $position->entry_price, 2)),
        ];

        $investmentLine = self::plannedInvestmentLine($position);

        if ($investmentLine !== null) {
            $lines[] = $investmentLine;
        }

        $lines[] = 'Plaats nu je stop order — niet vóór market open.';

        $brokerUrl = BrokerDeepLink::forStock($broker, $position->ticker);
        $brokerLabel = BrokerDeepLink::linkLabel($broker);

        if ($brokerUrl !== null && $brokerLabel !== null) {
            $lines[] = sprintf('<a href="%s">%s</a>', e($brokerUrl), e($brokerLabel));
        }

        $lines[] = sprintf('<a href="%s">Open setup</a>', ScoutResource::getUrl('edit', ['record' => $position]));

        return implode("\n", $lines);
    }

    private static function plannedInvestmentLine(Position $position): ?string
    {
        $entry = (float) $position->entry_price;
        $quantity = $position->quantity !== null ? (float) $position->quantity : null;

        if ($entry <= 0 || $quantity === null || $quantity <= 0) {
            return null;
        }

        $investment = $entry * $quantity;
        $sharesLabel = floor($quantity) === $quantity
            ? sprintf('≈ %d aandelen', (int) $quantity)
            : sprintf('≈ %s aandelen', rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.'));

        return sprintf('Inleg: $%s (%s)', number_format($investment, 2), $sharesLabel);
    }

    private static function trailingModeSuffix(Position $position): string
    {
        return match (StopLossProtocol::activeMode($position)) {
            TrailingStopMode::AggressivePreEarnings => ' (pre-earnings escalatie: '.StopLossProtocol::aggressiveFormulaLabel().')',
            TrailingStopMode::AggressiveOverbought => ' (oververhit: '.StopLossProtocol::overboughtFormulaLabel().')',
            default => '',
        };
    }

    public static function formatActionLabel(Position $position): string
    {
        if ($position->requiresEarningsExit()) {
            return match ($position->earningsExitUrgency()) {
                EarningsExitUrgency::Prepare => 'EARNINGS — bereid exit voor',
                EarningsExitUrgency::ExitToday => 'EARNINGS — sluit vandaag',
                EarningsExitUrgency::Overdue => 'EARNINGS — te laat!',
                default => $position->action_command,
            };
        }

        return $position->action_command;
    }

    /**
     * @param  list<Position>  $positions
     */
    public static function dailyDigest(User $user, array $positions): string
    {
        if ($positions === []) {
            return '<b>Dagelijkse digest</b>: Geen actiepunten. Set &amp; Forget.';
        }

        $lines = ['<b>Dagelijkse digest</b> — actiepunten:'];

        foreach ($positions as $position) {
            $lines[] = sprintf(
                '• %s: %s',
                e($position->ticker),
                e(self::formatActionLabel($position)),
            );
        }

        $lines[] = sprintf('<a href="%s">Open Vestix</a>', url('/admin'));

        return implode("\n", $lines);
    }
}
