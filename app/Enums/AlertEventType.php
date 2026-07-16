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
    case EarningsFinalReminder = 'earnings_final_reminder';
    case Target1Hit = 'target_1_hit';
    case Overbought = 'overbought';
    case MarketOpenBuyStopReminder = 'market_open_buy_stop_reminder';
    case ExecutionOrderPlan = 'execution_order_plan';
    case ExecutionPrepDigest = 'execution_prep_digest';

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
            self::EarningsFinalReminder->value,
            self::Target1Hit->value,
            self::Overbought->value,
            self::MarketOpenBuyStopReminder->value,
            self::ExecutionOrderPlan->value,
            self::ExecutionPrepDigest->value,
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

    public function label(): string
    {
        return match ($this) {
            self::SlCanRaise => 'Stop-loss kan verhoogd worden',
            self::FreerideSecured => 'Freeride secured (winst veiliggesteld)',
            self::StoppedOut => 'Stopped out',
            self::Target1Hit => 'Target 1 bereikt — verkoop 50%',
            self::DailyDigest => 'Dagelijkse digest',
            self::PremarketGapRisk => 'Pre-market gap-up waarschuwing (14:30)',
            self::PremarketReclamation => 'Pre-market reclamation — herovert SMA 20 (14:30)',
            self::PremarketLanding => 'Pre-market landing — nadert SMA 20 (14:30)',
            self::EarningsWarning => 'Earnings waarschuwing — bereid exit voor (08:00)',
            self::EarningsActionRequired => 'Earnings actie — sluit vóór slotbel (08:00)',
            self::EarningsFinalReminder => 'Earnings laatste kans — BMO exit (21:30)',
            self::Overbought => 'Overbought alert — RSI ≥ 70 (23:00)',
            self::MarketOpenBuyStopReminder => 'Buy-stop reminder bij market open (legacy)',
            self::ExecutionOrderPlan => 'Gap Reality Check na open (15:31)',
            self::ExecutionPrepDigest => 'Daily Execution Digest — Stop-Limit plannen (14:30)',
            self::SquadCopyAlert => 'Squad copy-alerts (Ghost Mode)',
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function labeledGroups(): array
    {
        return [
            'order' => self::optionsFor(
                self::StoppedOut,
                self::Target1Hit,
                self::FreerideSecured,
                self::SlCanRaise,
            ),
            'premarket' => self::optionsFor(
                self::PremarketGapRisk,
                self::PremarketReclamation,
                self::PremarketLanding,
                self::ExecutionPrepDigest,
                self::ExecutionOrderPlan,
                self::MarketOpenBuyStopReminder,
            ),
            'risk' => self::optionsFor(
                self::EarningsWarning,
                self::EarningsActionRequired,
                self::EarningsFinalReminder,
                self::Overbought,
            ),
            'squad' => self::optionsFor(
                self::SquadCopyAlert,
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function optionsFor(self ...$cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
