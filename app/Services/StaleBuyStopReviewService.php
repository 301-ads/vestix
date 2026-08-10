<?php

namespace App\Services;

use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use Illuminate\Support\Carbon;

class StaleBuyStopReviewService
{
    /**
     * Only after the Amsterdam cutoff (default 22:00) — never during the trading day
     * when Forceer API Sync / morning scout sync runs. Unfilled buy-stops stay in
     * Order Plan “Actief” until the evening review window.
     */
    public function canFlagNow(?Carbon $now = null): bool
    {
        $timezone = (string) config('vestix.stale_buy_stop_review.timezone', 'Europe/Amsterdam');
        $now ??= Carbon::now($timezone);
        $now = $now->copy()->timezone($timezone);

        [$hour, $minute] = $this->parseClockTime(
            (string) config('vestix.stale_buy_stop_review.after_time', '22:00'),
        );

        $cutoff = $now->copy()->startOfDay()->setTime($hour, $minute);

        return $now->greaterThanOrEqualTo($cutoff);
    }

    public function flagStaleBuyStops(?Carbon $reviewDate = null, ?Carbon $now = null): int
    {
        if (! $this->canFlagNow($now)) {
            return 0;
        }

        $timezone = (string) config('vestix.stale_buy_stop_review.timezone', 'Europe/Amsterdam');
        $reviewDate ??= ($now ?? Carbon::now($timezone))->copy()->timezone($timezone)->startOfDay();
        $reviewDateString = $reviewDate->toDateString();

        $scouts = Position::query()
            ->scout()
            ->where('broker_order_status', BrokerOrderStatus::Pending)
            ->get();

        $flagged = 0;

        foreach ($scouts as $scout) {
            if (
                $scout->buy_stop_review_required_on !== null
                && $scout->buy_stop_review_required_on->toDateString() >= $reviewDateString
            ) {
                continue;
            }

            $scorecard = $scout->evaluateSetupScore();

            $scout->update([
                'broker_order_status' => BrokerOrderStatus::Scout,
                'market_open_reminder_on' => null,
                'order_plan_excluded_on' => null,
                'buy_stop_review_required_on' => $reviewDateString,
                'buy_stop_review_setup_score' => $scorecard['totalPoints'],
                'buy_stop_review_setup_grade' => $scorecard['grade'],
            ]);

            $flagged++;
        }

        return $flagged;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseClockTime(string $time): array
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return [(int) $hour, (int) $minute];
    }
}
