<?php

namespace App\Models;

use App\Enums\SquadActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SquadActivity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SquadActivityType::class,
            'meta' => 'array',
        ];
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function summary(): string
    {
        $actor = $this->actor?->name ?? 'Iemand';
        $ticker = $this->ticker;

        return match ($this->type) {
            SquadActivityType::Shared => "{$actor} deelde {$ticker}",
            SquadActivityType::Cloned => "{$actor} kloonde {$ticker}",
            SquadActivityType::Opened => "{$actor} opende {$ticker}",
            SquadActivityType::Closed => $this->closedSummary($actor, $ticker),
        };
    }

    private function closedSummary(string $actor, string $ticker): string
    {
        $roi = $this->meta['roi_pct'] ?? null;

        if (is_numeric($roi)) {
            $sign = (float) $roi >= 0 ? '+' : '';

            return "{$actor} sloot {$ticker} ({$sign}".number_format((float) $roi, 2).'%)';
        }

        return "{$actor} sloot {$ticker}";
    }
}
