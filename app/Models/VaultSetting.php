<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_monthly_budget' => 'decimal:2',
            'dry_powder_balance' => 'decimal:2',
            'overheat_threshold_pct' => 'decimal:2',
            'crash_threshold_pct' => 'decimal:2',
            'overheat_invest_fraction' => 'decimal:4',
            'dip_dry_powder_fraction' => 'decimal:4',
            'crash_dry_powder_fraction' => 'decimal:4',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function defaultsFor(User $user): self
    {
        $config = config('vestix.kluis', []);

        return new self([
            'user_id' => $user->id,
            'etf_ticker' => (string) ($config['default_etf_ticker'] ?? 'VWCE'),
            'default_monthly_budget' => (float) ($config['default_monthly_budget'] ?? 10000),
            'dry_powder_balance' => 0,
            'overheat_threshold_pct' => (float) ($config['overheat_threshold_pct'] ?? 10),
            'crash_threshold_pct' => (float) ($config['crash_threshold_pct'] ?? 10),
            'overheat_invest_fraction' => (float) ($config['overheat_invest_fraction'] ?? 0.5),
            'dip_dry_powder_fraction' => (float) ($config['dip_dry_powder_fraction'] ?? 0.25),
            'crash_dry_powder_fraction' => (float) ($config['crash_dry_powder_fraction'] ?? 0.5),
        ]);
    }
}
