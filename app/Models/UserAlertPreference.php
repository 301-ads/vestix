<?php

namespace App\Models;

use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAlertPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel_type' => AlertChannelType::class,
            'active_events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasEventEnabled(AlertEventType $event): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $events = $this->active_events ?? AlertEventType::defaults();

        return in_array($event->value, $events, true);
    }

    public static function ensureDefaultsForUser(User $user): void
    {
        foreach (AlertChannelType::cases() as $channel) {
            if ($channel === AlertChannelType::Email) {
                continue;
            }

            self::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'channel_type' => $channel,
                ],
                [
                    'active_events' => AlertEventType::defaults(),
                    'daily_digest_time' => '21:45:00',
                    'timezone' => 'Europe/Amsterdam',
                    'is_active' => true,
                ],
            );
        }
    }
}
