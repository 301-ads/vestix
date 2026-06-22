<?php

namespace App\Support;

use App\Enums\AlertEventType;
use App\Enums\EarningsExitUrgency;
use App\Filament\Resources\Scouts\ScoutResource;
use App\Filament\Resources\Positions\PositionResource;
use App\Models\Position;
use App\Models\User;

class AlertMessageBuilder
{
    public static function forEvent(AlertEventType $event, Position $position, array $context = []): string
    {
        $loginUrl = url('/admin');

        return match ($event) {
            AlertEventType::SlCanRaise => sprintf(
                '<b>%s</b>: stop-loss kan wiskundig verhoogd worden naar $%s. <a href="%s">Inloggen</a>',
                e($position->ticker),
                number_format((float) ($context['new_sl'] ?? $position->new_sl), 2),
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
                '<b>[Swingdash] WAARSCHUWING:</b> %s noteert $%s in pre-market. Dit is %s%% boven je entry-trigger ($%s). Risico op chasing — overweeg order te annuleren. <a href="%s">Open setup</a>',
                e($position->ticker),
                number_format((float) ($context['premarket_price'] ?? $position->premarket_price ?? 0), 2),
                number_format((float) ($context['gap_pct'] ?? $position->premarket_gap_pct ?? 0), 2),
                number_format((float) ($context['entry_trigger'] ?? $position->premarket_entry_trigger ?? $position->entry_price ?? 0), 2),
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
