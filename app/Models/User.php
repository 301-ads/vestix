<?php

namespace App\Models;

use App\Enums\Broker;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'telegram_chat_id', 'telegram_link_token', 'default_risk_per_trade', 'trading_bankroll', 'default_risk_percent', 'primary_broker'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function squads(): BelongsToMany
    {
        return $this->belongsToMany(Squad::class)->withTimestamps();
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function alertPreferences(): HasMany
    {
        return $this->hasMany(UserAlertPreference::class);
    }

    public function bankrollSnapshots(): HasMany
    {
        return $this->hasMany(BankrollSnapshot::class);
    }

    public function positionAlerts(): HasMany
    {
        return $this->hasMany(PositionAlert::class);
    }

    public function leaderboardStats(): HasMany
    {
        return $this->hasMany(LeaderboardStat::class);
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function resolveTelegramChatId(): ?string
    {
        if (filled($this->telegram_chat_id)) {
            return $this->telegram_chat_id;
        }

        return null;
    }

    public function ensureTelegramLinkToken(): string
    {
        if (filled($this->telegram_link_token)) {
            return $this->telegram_link_token;
        }

        $token = bin2hex(random_bytes(16));

        $this->forceFill(['telegram_link_token' => $token])->save();

        return $token;
    }

    public function clearTelegramConnection(): void
    {
        $this->forceFill([
            'telegram_chat_id' => null,
            'telegram_link_token' => null,
        ])->save();
    }

    public function hasTelegramConnection(): bool
    {
        return filled($this->telegram_chat_id);
    }

    public function usesRevolutWorkflow(): bool
    {
        return $this->primary_broker === Broker::Revolut;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
            'default_risk_per_trade' => 'decimal:2',
            'trading_bankroll' => 'decimal:2',
            'default_risk_percent' => 'decimal:2',
            'primary_broker' => Broker::class,
        ];
    }
}
