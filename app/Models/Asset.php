<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Asset extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
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
}
