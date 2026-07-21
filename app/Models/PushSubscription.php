<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            if (filled($subscription->endpoint)) {
                $subscription->endpoint_hash = self::hashEndpoint((string) $subscription->endpoint);
            }
        });
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
