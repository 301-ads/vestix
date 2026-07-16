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
            if ($position->trader_promoted_a_plus) {
                $position->clearAPlusPromotion();
            }

            if ($position->trader_promoted_a) {
                $position->clearAPromotion();
            }

            return 0;
        }

        $owner = $position->user;

        if ($owner === null) {
            return 0;
        }

        $newScore = $newScorecard['totalPoints'];
        $maxPoints = $newScorecard['maxPoints'];
        $alertsSent = 0;

        if (
            $previousScore < 8
            && $newScore >= 8
            && $newScore < $maxPoints
            && $position->telegram_a_minus_alert_sent_at === null
        ) {
            $message = sprintf(
                '🎯 Sniper Alert: %s is nu een A SETUP (%d/%d). Koers is geland op de SMA 20. Open de setup en vul Low/High in voor je Buy-Stop.',
                $position->ticker,
                $newScore,
                $maxPoints,
            );

            if (TelegramNotifier::sendToUser($owner, $message)) {
                $position->update(['telegram_a_minus_alert_sent_at' => now()]);
                $alertsSent++;
            }
        }

        if (
            $previousScore < $maxPoints
            && $newScore === $maxPoints
            && $position->telegram_a_plus_alert_sent_at === null
        ) {
            $message = sprintf(
                '✨ Perfecte score: %s haalt %d/%d punten. Beoordeel visueel op de radar en promoveer naar A++ als de setup klopt.',
                $position->ticker,
                $newScore,
                $maxPoints,
            );

            if (TelegramNotifier::sendToUser($owner, $message)) {
                $position->update(['telegram_a_plus_alert_sent_at' => now()]);
                $alertsSent++;
            }
        }

        if ($newScore < $maxPoints && $position->trader_promoted_a_plus) {
            $position->clearAPlusPromotion();
        }

        if ($newScore < 8 && $position->trader_promoted_a) {
            $position->clearAPromotion();
        }

        return $alertsSent;
    }

    public function notifyManualAPlusPromotion(Position $position): bool
    {
        if ($position->status !== 'scout') {
            return false;
        }

        $owner = $position->user;

        if ($owner === null) {
            return false;
        }

        $scorecard = $position->evaluateSetupScore();

        if ($scorecard['grade'] !== 'A++') {
            return false;
        }

        $message = sprintf(
            '🔥 UPGRADE: %s is handmatig gepromoveerd naar een A++ SETUP (%d/%d). Maximale edge bevestigd door jouw visuele check!',
            $position->ticker,
            $scorecard['totalPoints'],
            $scorecard['maxPoints'],
        );

        return TelegramNotifier::sendToUser($owner, $message);
    }
}
