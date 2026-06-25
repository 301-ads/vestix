<?php

namespace App\Support;

use App\Models\Position;

class ScoutSetupAlertService
{
    /**
     * @param  array{
     *     totalPoints: int,
     *     maxPoints: int,
     *     grade: string,
     *     gradeLabel: string,
     *     hardFailReasons: array<int, string>,
     *     criteria: array<int, array<string, mixed>>,
     * }  $newScorecard
     */
    public function evaluateAndNotify(Position $position, int $previousScore, array $newScorecard): int
    {
        if ($position->status !== 'scout') {
            return 0;
        }

        if ($newScorecard['hardFailReasons'] !== []) {
            return 0;
        }

        $owner = $position->user;

        if ($owner === null) {
            return 0;
        }

        $newScore = $newScorecard['totalPoints'];
        $alertsSent = 0;

        if (
            $previousScore < 6
            && $newScore >= 6
            && $position->telegram_a_minus_alert_sent_at === null
        ) {
            $message = sprintf(
                '🎯 Sniper Alert: %s is nu een A- Setup (%d/%d). Koers is geland op de SMA 20. Open de setup en vul Low/High in voor je Buy-Stop.',
                $position->ticker,
                $newScore,
                $newScorecard['maxPoints'],
            );

            if (TelegramNotifier::sendToUser($owner, $message)) {
                $position->update(['telegram_a_minus_alert_sent_at' => now()]);
                $alertsSent++;
            }
        }

        if (
            $previousScore < 7
            && $newScore === 7
            && $position->telegram_a_plus_alert_sent_at === null
        ) {
            $message = sprintf(
                '🔥 UPGRADE: %s is geëvolueerd naar een A+ Setup (7/7). Massaal kopersvolume bevestigd op de bounce!',
                $position->ticker,
            );

            if (TelegramNotifier::sendToUser($owner, $message)) {
                $position->update(['telegram_a_plus_alert_sent_at' => now()]);
                $alertsSent++;
            }
        }

        return $alertsSent;
    }
}
