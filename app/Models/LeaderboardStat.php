<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaderboardStat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'win_rate' => 'decimal:2',
            'avg_roi_pct' => 'decimal:2',
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
