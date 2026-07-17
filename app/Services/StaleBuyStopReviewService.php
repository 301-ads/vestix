<?php

namespace App\Services;

use App\Enums\BrokerOrderStatus;
use App\Models\Position;
use Illuminate\Support\Carbon;

class StaleBuyStopReviewService
{
    public function flagStaleBuyStops(?Carbon $reviewDate = null): int
    {
        $reviewDate ??= Carbon::now('Europe/Amsterdam')->startOfDay();
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
}
