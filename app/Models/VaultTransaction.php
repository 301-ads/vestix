<?php

namespace App\Models;

use App\Enums\VaultTransactionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'traded_at' => 'datetime',
            'shares' => 'decimal:6',
            'fill_price' => 'decimal:4',
            'etf_amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'source' => VaultTransactionSource::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vaultDeposit(): BelongsTo
    {
        return $this->belongsTo(VaultDeposit::class);
    }

    public function costBasis(): float
    {
        return round((float) $this->etf_amount + (float) $this->fee, 2);
    }
}
