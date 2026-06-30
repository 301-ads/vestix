<?php

namespace App\Enums;

enum AlertEventType: string
{
    case SlCanRaise = 'sl_can_raise';
    case FreerideSecured = 'freeride_secured';
    case StoppedOut = 'stopped_out';
    case DailyDigest = 'daily_digest';
    case SquadCopyAlert = 'squad_copy_alert';
    case PremarketGapRisk = 'premarket_gap_risk';
    case PremarketReclamation = 'premarket_reclamation';
    case PremarketLanding = 'premarket_landing';
    case EarningsWarning = 'earnings_warning';
    case EarningsActionRequired = 'earnings_action_required';
    case Overbought = 'overbought';

    /**
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            self::SlCanRaise->value,
            self::FreerideSecured->value,
            self::StoppedOut->value,
            self::DailyDigest->value,
            self::PremarketGapRisk->value,
            self::PremarketReclamation->value,
            self::PremarketLanding->value,
            self::EarningsWarning->value,
            self::EarningsActionRequired->value,
            self::Overbought->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(
            fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
