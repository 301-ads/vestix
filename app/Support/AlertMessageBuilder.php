<?php

namespace App\Support;

use App\Enums\AlertEventType;
use App\Enums\Broker;
use App\Enums\EarningsExitUrgency;
use App\Enums\ExecutionDigestStatus;
use App\Enums\TrailingStopMode;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;

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
                '⚠️ <b>EARNINGS WARNING:</b> %s publiceert binnenkort cijfers (%s). Sluit uiterlijk %s vóór slotbel. <a href="%s">Open positie</a>',
                e($position->ticker),
                e($context['earnings_date'] ?? '—'),
                e(isset($context['exit_deadline'])
                    ? Carbon::parse($context['exit_deadline'])->locale('nl')->isoFormat('ddd D MMM')
                    : 'de exit-deadline'),
                PositionResource::getUrl('edit', ['record' => $position]),
            ),
            AlertEventType::EarningsActionRequired => self::earningsActionRequiredMessage($position, $context),
            AlertEventType::EarningsFinalReminder => sprintf(
                '⏰ <b>EARNINGS — LAATSTE KANS:</b> %s (BMO) moet vóór 22:00 gesloten worden. Nog 30 minuten — verkoop nu en archiveer. <a href="%s">Open positie</a>',
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
            AlertEventType::Target1Hit => self::target1HitMessage($position, $context),
            AlertEventType::MarketOpenBuyStopReminder => self::marketOpenBuyStopReminder($position, $context),
            AlertEventType::ExecutionOrderPlan => $context['digest_body'] ?? 'Geen Gap Reality Check vandaag.',
            AlertEventType::ExecutionPrepDigest => $context['digest_body'] ?? 'Geen Execution Digest vandaag.',
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function earningsActionRequiredMessage(Position $position, array $context): string
    {
        $loginUrl = PositionResource::getUrl('edit', ['record' => $position]);
        $deadlineLabel = isset($context['exit_deadline'])
            ? Carbon::parse($context['exit_deadline'])->locale('nl')->isoFormat('ddd D MMM')
            : 'deadline';

        return match ($context['reminder'] ?? 'today') {
            'tomorrow' => sprintf(
                '🚨 <b>EARNINGS EXIT:</b> %s publiceert %s cijfers. Sluit morgen (%s) vóór slotbel (22:00) alle posities. <a href="%s">Open positie</a>',
                e($position->ticker),
                e($context['earnings_date'] ?? 'binnenkort'),
                e($deadlineLabel),
                $loginUrl,
            ),
            default => sprintf(
                '🚨 <b>EARNINGS ACTION REQUIRED:</b> %s — sluit vandaag (%s) vóór slotbel (22:00) en archiveer de positie. <a href="%s">Open positie</a>',
                e($position->ticker),
                e($deadlineLabel),
                $loginUrl,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function target1HitMessage(Position $position, array $context): string
    {
        $position->loadMissing('user');

        $targetPrice = (float) ($context['target_1_price'] ?? $position->target_1_price ?? 0);
        $fractionPercent = (int) round($position->effective_first_tranche_fraction * 100);
        $loginUrl = PositionResource::getUrl('edit', ['record' => $position]);

        if ($position->userUsesRevolutWorkflow()) {
            return sprintf(
                '<b>TARGET 1 BEREIKT:</b> %s ≥ $%s — verkoop %d%% handmatig bij Revolut.<br>'
                .'1. Pas/annuleer je 100%% stop-loss tijdelijk<br>'
                .'2. Verkoop %d%% op de markt<br>'
                .'3. Zet nieuwe stop-loss op breakeven voor de runner<br>'
                .'4. <a href="%s">Log verkoop in Vestix</a>',
                e($position->ticker),
                number_format($targetPrice, 2),
                $fractionPercent,
                $fractionPercent,
                $loginUrl,
            );
        }

        return sprintf(
            '<b>TARGET 1 BEREIKT:</b> %s close ≥ $%s — verkoop %s%% en zet stop op breakeven. <a href="%s">Log verkoop</a>',
            e($position->ticker),
            number_format($targetPrice, 2),
            number_format($fractionPercent, 0),
            $loginUrl,
        );
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

    /**
     * Pre-open Daily Execution Digest (14:30): all Stop-Limit plans for today.
     *
     * @param  list<Position>  $positions
     */
    public static function executionPrepDigest(User $user, array $positions, string $reminderDate): string
    {
        $lines = [
            sprintf(
                '📋 <b>Vestix Daily Execution Digest — %s</b>',
                e(Carbon::parse($reminderDate)->locale('nl')->isoFormat('ddd D MMM')),
            ),
            '',
            '🛑 <b>STOP-LIMIT PLANNEN (plaats vóór 15:25, TIF = GTC):</b>',
            '',
        ];

        if ($positions === []) {
            $lines[] = 'Geen goedgekeurde setups voor vandaag.';
            $lines[] = '';
        } else {
            foreach ($positions as $position) {
                $lines = [...$lines, ...self::safeOrderPlanBlock($position), ''];
            }
        }

        $lines[] = 'Neem elk plan 1-op-1 over in TradingView: Order Type = STOP LIMIT, Time in Force = GTC.';
        $lines[] = sprintf('<a href="%s">Open Mijn Radar</a>', ScoutResource::getUrl('index'));

        return implode("\n", $lines);
    }

    /**
     * Post-open Gap Reality Check (15:31): only Stop-Limits that were likely skipped.
     *
     * @param  list<array{position: Position, status: ExecutionDigestStatus, reason: string, price: float|null}>  $skippedRows
     */
    public static function executionRealityCheck(User $user, array $skippedRows, string $reminderDate): string
    {
        $lines = [
            sprintf(
                'ℹ️ <b>Vestix Gap Reality Check — %s</b>',
                e(Carbon::parse($reminderDate)->locale('nl')->isoFormat('ddd D MMM')),
            ),
            '',
            '⚠️ <b>STOP-LIMIT WAARSCHIJNLIJK OVERGESLAGEN:</b>',
            '',
        ];

        foreach ($skippedRows as $row) {
            $lines[] = sprintf(
                '<b>$%s</b> %s',
                e($row['position']->ticker),
                e($row['reason']),
            );
        }

        $lines[] = '';
        $lines[] = 'Geen emotie — je wiskunde is gered. Verwijder deze setups uit TradingView.';
        $lines[] = sprintf('<a href="%s">Open Mijn Radar</a>', ScoutResource::getUrl('index'));

        return implode("\n", $lines);
    }

    /**
     * @deprecated Use executionPrepDigest / executionRealityCheck.
     *
     * @param  list<array{position: Position, status: ExecutionDigestStatus, reason: string, price: float|null}>  $rows
     */
    public static function executionOrderPlan(User $user, array $rows, string $reminderDate): string
    {
        $skipped = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['status'] === ExecutionDigestStatus::CancelledGapUp,
        ));

        if ($skipped === []) {
            return self::executionPrepDigest(
                $user,
                array_map(fn (array $row): Position => $row['position'], $rows),
                $reminderDate,
            );
        }

        return self::executionRealityCheck($user, $skipped, $reminderDate);
    }

    /**
     * @return list<string>
     */
    private static function safeOrderPlanBlock(Position $position): array
    {
        $entry = (float) ($position->entry_price ?? 0);
        $limitPrice = StopLimitBuffer::limitPrice($entry);
        $quantity = (float) ($position->quantity ?? 0);
        $stopLoss = (float) ($position->new_sl ?? 0);
        $target1 = (float) ($position->plannedBracketTarget1Price() ?? 0);
        $riskDollars = $position->planned_risk_dollars;

        $lines = [
            sprintf(
                '<b>$%s</b>%s',
                e($position->ticker),
                $riskDollars !== null
                    ? sprintf(' (Risico: $%s)', number_format((float) $riskDollars, 2))
                    : '',
            ),
            'Type: STOP LIMIT (Kopen)',
        ];

        if ($quantity > 0) {
            $qtyLabel = floor($quantity) === $quantity
                ? (string) (int) $quantity
                : rtrim(rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
            $lines[] = sprintf('Aantal: %s', $qtyLabel);
        }

        if ($entry > 0) {
            $lines[] = sprintf('Buy-Stop: $%s', number_format($entry, 2));
            $lines[] = sprintf('Limit Prijs: $%s', number_format($limitPrice, 2));
        }

        if ($stopLoss > 0) {
            $lines[] = sprintf('Stop-Loss: $%s', number_format($stopLoss, 2));
        }

        if ($target1 > 0) {
            $lines[] = sprintf('Take Profit: $%s', number_format($target1, 2));
        }

        return $lines;
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
            $earningsDate = $position->effectiveEarningsDate();
            $hour = $position->asset?->effectiveEarningsHour();

            return match ($position->earningsExitUrgency()) {
                EarningsExitUrgency::Prepare => EarningsExitSchedule::daysUntilAction($earningsDate, null, $hour) === 1
                    ? 'EARNINGS — sluit morgen'
                    : 'EARNINGS — bereid exit voor',
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
