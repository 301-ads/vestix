<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\User;
use App\Support\AlertMessageBuilder;
use App\Support\Entitlements;
use Illuminate\Support\Facades\Log;

class WeeklyEdgeDigestService
{
    public function __construct(
        private StrategyAnalyticsService $analytics,
        private ProtocolComplianceService $protocol,
        private AlertDispatcher $dispatcher,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(): array
    {
        $sent = 0;
        $skipped = 0;

        User::query()->each(function (User $user) use (&$sent, &$skipped): void {
            if (! Entitlements::allows($user, Entitlements::FEATURE_WEEKLY_EDGE_DIGEST)) {
                $skipped++;

                return;
            }

            if (! $user->hasTelegramConnection() && ! $user->hasPushSubscription()) {
                $skipped++;

                return;
            }

            $message = $this->buildMessage($user);

            if ($message === null) {
                $skipped++;

                return;
            }

            try {
                $this->dispatcher->dispatchUserEvent(
                    $user,
                    AlertEventType::DailyDigest,
                    $message,
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Weekly edge digest failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        });

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    public function buildMessage(User $user): ?string
    {
        $userId = $user->id;
        $closed = $this->analytics->closedTradesForUser($userId);

        if ($closed->isEmpty()) {
            return null;
        }

        $stats = $this->analytics->overallStats($userId);
        $protocol = $this->protocol->summaryForUser($userId);
        $untilCoach = $this->analytics->tradesUntilCoach($userId);

        $lines = [
            '📊 Vestix Weekly Edge',
            '',
            sprintf('Trades: %d · Win rate: %.0f%%', $stats['total_trades'], $stats['win_rate']),
            sprintf('Expectancy: %.2f%% · Max DD: %.2f%%', $stats['expectancy'], $stats['max_drawdown']),
        ];

        if ($protocol['avg_score'] !== null) {
            $lines[] = sprintf(
                'Protocol: %.0f/100 avg (%d zwak)',
                $protocol['avg_score'],
                $protocol['weak_count'],
            );
        }

        if ($untilCoach > 0) {
            $lines[] = sprintf('Coach unlock: nog %d gesloten trades', $untilCoach);
        } else {
            $lines[] = 'Coach: unlocked — review Prestaties deze week.';
        }

        $lines[] = '';
        $lines[] = 'Focus: volg T1/BE/trail — Free-First, geen emotie.';

        return implode("\n", $lines);
    }
}
