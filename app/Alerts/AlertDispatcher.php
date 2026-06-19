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

            if ($this->alreadySent($position->id, $event, $preference->channel_type)) {
                continue;
            }

            if ($channel->send($user, $message)) {
                PositionAlert::query()->create([
                    'user_id' => $user->id,
                    'position_id' => $position->id,
                    'event_type' => $event,
                    'channel_type' => $preference->channel_type,
                    'payload' => $context,
                    'sent_at' => now(),
                ]);
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

    private function alreadySent(int $positionId, AlertEventType $event, AlertChannelType $channel): bool
    {
        if ($event === AlertEventType::DailyDigest) {
            return false;
        }

        return PositionAlert::query()
            ->where('position_id', $positionId)
            ->where('event_type', $event)
            ->where('channel_type', $channel)
            ->exists();
    }
}
