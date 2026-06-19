<?php

namespace App\Models;

use App\Enums\AlertChannelType;
use App\Enums\AlertEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_type' => AlertEventType::class,
            'channel_type' => AlertChannelType::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
