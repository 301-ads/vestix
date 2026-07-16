<?php

namespace App\Alerts;

use App\Alerts\Channels\EmailAlertChannel;
use App\Alerts\Channels\TelegramAlertChannel;
use App\Contracts\AlertChannelInterface;
use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use App\Jobs\SendAlertJob;
use App\Models\Position;
use App\Models\PositionAlert;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Support\AlertMessageBuilder;
use Illuminate\Support\Facades\Log;

class AlertDispatcher
{
    /**
     * @var array<string, AlertChannelInterface>
     */
    private array $channels;

    public function __construct()
    {
        $this->channels = [
            AlertChannelType::Telegram->value => new TelegramAlertChannel,
            AlertChannelType::Email->value => new EmailAlertChannel,
        ];
    }

    public function queue(Position $position, AlertEventType $event, array $context = []): void
    {
        SendAlertJob::dispatch($position->user_id, $position->id, $event, $context);
    }

    public function dispatchNow(
        int $userId,
        int $positionId,
        AlertEventType $event,
        array $context = [],
    ): bool {
        $user = User::query()->find($userId);
        $position = Position::query()->find($positionId);

        if (! $user instanceof User || ! $position instanceof Position) {
            return false;
        }

        UserAlertPreference::ensureDefaultsForUser($user);

        $message = AlertMessageBuilder::forEvent($event, $position, $context);
        $sent = false;

        foreach ($user->alertPreferences()->where('is_active', true)->get() as $preference) {
            if (! $preference->hasEventEnabled($event)) {
                continue;
            }

            $channel = $this->channels[$preference->channel_type->value] ?? null;

            if (! $channel instanceof AlertChannelInterface || ! $channel->isAvailableFor($user)) {
                continue;
            }

            if ($this->alreadySent($position->id, $event, $preference->channel_type, $context)) {
                continue;
            }

            if ($channel->send($user, $message)) {
                if (in_array($event, [
                    AlertEventType::PremarketGapRisk,
                    AlertEventType::MarketOpenBuyStopReminder,
                    AlertEventType::EarningsWarning,
                    AlertEventType::EarningsActionRequired,
                    AlertEventType::EarningsFinalReminder,
                ], true)) {
                    PositionAlert::query()->updateOrCreate(
                        [
                            'position_id' => $position->id,
                            'event_type' => $event,
                            'channel_type' => $preference->channel_type,
                        ],
                        [
                            'user_id' => $user->id,
                            'payload' => $context,
                            'sent_at' => now(),
                        ],
                    );
                } else {
                    PositionAlert::query()->create([
                        'user_id' => $user->id,
                        'position_id' => $position->id,
                        'event_type' => $event,
                        'channel_type' => $preference->channel_type,
                        'payload' => $context,
                        'sent_at' => now(),
                    ]);
                }
                $sent = true;
            } else {
                Log::warning('Alert delivery failed.', [
                    'user_id' => $user->id,
                    'position_id' => $position->id,
                    'event' => $event->value,
                    'channel' => $preference->channel_type->value,
                ]);
            }
        }

        return $sent;
    }

    public function dispatchDigest(User $user, string $message): void
    {
        UserAlertPreference::ensureDefaultsForUser($user);

        foreach ($user->alertPreferences()->where('is_active', true)->get() as $preference) {
            if (! $preference->hasEventEnabled(AlertEventType::DailyDigest)) {
                continue;
            }

            $channel = $this->channels[$preference->channel_type->value] ?? null;

            if (! $channel instanceof AlertChannelInterface || ! $channel->isAvailableFor($user)) {
                continue;
            }

            $channel->send($user, $message);
        }
    }

    /**
     * One Telegram/email Order Plan for multiple scouts (post-open Gap Guard).
     *
     * @param  list<Position>  $positions
     * @param  array{reminder_date: string, rows?: list<array{status: string, price: float|null}>}  $context
     */
    public function dispatchExecutionOrderPlan(User $user, string $message, array $positions, array $context): bool
    {
        UserAlertPreference::ensureDefaultsForUser($user);

        $sent = false;
        $reminderDate = (string) ($context['reminder_date'] ?? now('Europe/Amsterdam')->toDateString());

        foreach ($user->alertPreferences()->where('is_active', true)->get() as $preference) {
            $allows = $preference->hasEventEnabled(AlertEventType::ExecutionOrderPlan)
                || $preference->hasEventEnabled(AlertEventType::MarketOpenBuyStopReminder);

            if (! $allows) {
                continue;
            }

            $channel = $this->channels[$preference->channel_type->value] ?? null;

            if (! $channel instanceof AlertChannelInterface || ! $channel->isAvailableFor($user)) {
                continue;
            }

            if (! $channel->send($user, $message)) {
                continue;
            }

            $sent = true;

            foreach ($positions as $index => $position) {
                $rowMeta = $context['rows'][$index] ?? [];

                PositionAlert::query()->updateOrCreate(
                    [
                        'position_id' => $position->id,
                        'event_type' => AlertEventType::ExecutionOrderPlan,
                        'channel_type' => $preference->channel_type,
                    ],
                    [
                        'user_id' => $user->id,
                        'payload' => [
                            'reminder_date' => $reminderDate,
                            'status' => $rowMeta['status'] ?? null,
                            'price' => $rowMeta['price'] ?? null,
                        ],
                        'sent_at' => now(),
                    ],
                );
            }
        }

        return $sent;
    }

    private function alreadySent(int $positionId, AlertEventType $event, AlertChannelType $channel, array $context = []): bool
    {
        if ($event === AlertEventType::DailyDigest) {
            return false;
        }

        $query = PositionAlert::query()
            ->where('position_id', $positionId)
            ->where('event_type', $event)
            ->where('channel_type', $channel);

        if ($event === AlertEventType::PremarketGapRisk) {
            return $query
                ->whereDate('sent_at', now()->toDateString())
                ->exists();
        }

        if ($event === AlertEventType::MarketOpenBuyStopReminder) {
            $reminderDate = $context['reminder_date'] ?? null;

            if ($reminderDate === null) {
                return $query
                    ->whereDate('sent_at', now()->toDateString())
                    ->exists();
            }

            return $query
                ->where('payload->reminder_date', $reminderDate)
                ->exists();
        }

        if (in_array($event, [
            AlertEventType::EarningsWarning,
            AlertEventType::EarningsActionRequired,
            AlertEventType::EarningsFinalReminder,
        ], true)) {
            $earningsDate = $context['earnings_date'] ?? null;

            if ($earningsDate === null) {
                return $query->exists();
            }

            $query->where('payload->earnings_date', $earningsDate);

            if ($event === AlertEventType::EarningsActionRequired) {
                $reminder = $context['reminder'] ?? 'today';

                return $query
                    ->where('payload->reminder', $reminder)
                    ->whereDate('sent_at', now()->toDateString())
                    ->exists();
            }

            if ($event === AlertEventType::EarningsFinalReminder) {
                return $query
                    ->whereDate('sent_at', now()->toDateString())
                    ->exists();
            }

            return $query->exists();
        }

        return $query->exists();
    }
}
