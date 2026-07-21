<?php

namespace App\Models;

use App\Enums\Broker;
use App\Enums\TradeDirection;
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

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'is_super_admin', 'is_discoverable', 'telegram_chat_id', 'telegram_link_token', 'default_risk_per_trade', 'trading_bankroll', 'ibkr_net_liquidation', 'ibkr_available_funds', 'ibkr_settled_cash', 'ibkr_base_currency', 'ibkr_open_positions', 'ibkr_open_orders', 'ibkr_last_success_at', 'ibkr_last_attempt_at', 'ibkr_last_error', 'ibkr_data_stale', 'baseline_capital', 'baseline_date', 'default_risk_percent', 'default_short_risk_percent', 'primary_broker', 'is_short_enabled'])]
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

    public function bankrollCashflows(): HasMany
    {
        return $this->hasMany(BankrollCashflow::class);
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

    public function canUseShort(): bool
    {
        return (bool) $this->is_short_enabled;
    }

    public function defaultRiskPercentFor(TradeDirection|string|null $direction = null): float
    {
        $direction = match (true) {
            $direction instanceof TradeDirection => $direction,
            is_string($direction) && $direction === TradeDirection::Short->value => TradeDirection::Short,
            default => TradeDirection::Long,
        };

        if ($direction === TradeDirection::Short && $this->canUseShort()) {
            return (float) ($this->default_short_risk_percent ?? $this->default_risk_percent ?? 1);
        }

        return (float) ($this->default_risk_percent ?? 1);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'is_discoverable' => 'boolean',
            'is_short_enabled' => 'boolean',
            'password' => 'hashed',
            'default_risk_per_trade' => 'decimal:2',
            'trading_bankroll' => 'decimal:2',
            'ibkr_net_liquidation' => 'decimal:2',
            'ibkr_available_funds' => 'decimal:2',
            'ibkr_settled_cash' => 'decimal:2',
            'ibkr_open_positions' => 'array',
            'ibkr_open_orders' => 'array',
            'ibkr_last_success_at' => 'datetime',
            'ibkr_last_attempt_at' => 'datetime',
            'ibkr_data_stale' => 'boolean',
            'baseline_capital' => 'decimal:2',
            'baseline_date' => 'date',
            'default_risk_percent' => 'decimal:2',
            'default_short_risk_percent' => 'decimal:2',
            'primary_broker' => Broker::class,
        ];
    }
}
