<?php

namespace App\Models;

use App\Enums\LeaderboardTrack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'track' => LeaderboardTrack::class,
            'win_rate' => 'decimal:2',
            'avg_roi_pct' => 'decimal:2',
            'total_r' => 'decimal:2',
            'avg_r' => 'decimal:2',
            'discipline_score_30d' => 'decimal:2',
            'freeride_count' => 'integer',
            'closed_trades_count' => 'integer',
            'rank' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
