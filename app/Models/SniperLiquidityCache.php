<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SniperLiquidityCache extends Model
{
    protected $table = 'sniper_liquidity_cache';

    protected $primaryKey = 'ticker';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'avg_volume_30d' => 'integer',
            'last_volume' => 'integer',
            'market_cap' => 'decimal:2',
            'enabled' => 'boolean',
            'bars_ready' => 'boolean',
            'metrics_as_of' => 'date',
            'market_cap_fetched_at' => 'datetime',
            'split_purged_at' => 'datetime',
        ];
    }
}
