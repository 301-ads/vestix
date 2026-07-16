<?php

namespace App\Models;

use App\Enums\BankrollCashflowType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankrollCashflow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => BankrollCashflowType::class,
            'amount' => 'decimal:2',
            'occurred_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return $this->type === BankrollCashflowType::Withdrawal
            ? -abs($amount)
            : abs($amount);
    }
}
