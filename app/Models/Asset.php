<?php

namespace App\Models;

use App\Enums\EarningsReleaseHour;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'next_earnings_date' => 'date',
            'next_earnings_hour' => EarningsReleaseHour::class,
            'earnings_date_override' => 'date',
            'earnings_hour_override' => EarningsReleaseHour::class,
            'earnings_fetched_at' => 'datetime',
        ];
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function hasIcon(): bool
    {
        if (blank($this->icon_path)) {
            return false;
        }

        return Storage::disk('public')->exists($this->icon_path);
    }

    public function getIconUrlAttribute(): ?string
    {
        if (! $this->hasIcon()) {
            return null;
        }

        return '/storage/'.ltrim($this->icon_path, '/');
    }

    public static function normalizeTicker(string $ticker): string
    {
        return strtoupper(trim($ticker));
    }

    public function effectiveEarningsDate(): ?Carbon
    {
        $date = $this->earnings_date_override ?? $this->next_earnings_date;

        if ($date === null) {
            return null;
        }

        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    public function effectiveEarningsHour(): EarningsReleaseHour
    {
        return $this->earnings_hour_override
            ?? $this->next_earnings_hour
            ?? EarningsReleaseHour::Unknown;
    }
}
