<?php

namespace App\Models;

use App\Enums\KluisClimate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultDeposit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'climate' => KluisClimate::class,
            'deviation_pct' => 'decimal:4',
            'budget_input' => 'decimal:2',
            'etf_amount' => 'decimal:2',
            'dry_powder_delta' => 'decimal:2',
            'dry_powder_after' => 'decimal:2',
            'etf_price' => 'decimal:4',
            'sma_200' => 'decimal:4',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
