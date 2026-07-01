<?php

namespace App\Services;

use App\Alerts\AlertDispatcher;
use App\Enums\AlertEventType;
use App\Models\Position;
use App\Support\UsMarketSession;
use Illuminate\Support\Carbon;

class MarketOpenBuyStopReminderService
{
    public function __construct(
        private readonly AlertDispatcher $alertDispatcher,
    ) {}

    /**
     * @return array{sent: int, skipped: int}
     */
    public function run(?Carbon $today = null): array
    {
        $today ??= Carbon::today('Europe/Amsterdam');
        $summary = ['sent' => 0, 'skipped' => 0];

        if (! UsMarketSession::isUsTradingDay($today->copy()->timezone('America/New_York'))) {
            return $summary;
        }

        $reminderDate = $today->toDateString();

        $scouts = Position::query()
            ->where('status', 'scout')
            ->whereDate('market_open_reminder_on', $reminderDate)
            ->whereNotNull('entry_price')
            ->with('user')
            ->get();

        foreach ($scouts as $scout) {
            $user = $scout->user;

            if ($user === null || ! $user->hasTelegramConnection()) {
                $summary['skipped']++;

                continue;
            }

            $context = [
                'reminder_date' => $reminderDate,
                'user' => $user,
            ];

            $this->alertDispatcher->queue($scout, AlertEventType::MarketOpenBuyStopReminder, $context);

            $scout->update(['market_open_reminder_on' => null]);

            $summary['sent']++;
        }

        return $summary;
    }
}
