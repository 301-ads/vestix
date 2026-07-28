<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SniperDailyBar extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'open' => 'decimal:6',
            'high' => 'decimal:6',
            'low' => 'decimal:6',
            'close' => 'decimal:6',
            'volume' => 'integer',
        ];
    }
}
