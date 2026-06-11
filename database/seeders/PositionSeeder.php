<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        Position::create([
            'ticker' => 'WDC',
            'entry_price' => 76.94,
            'quantity' => 34,
            'current_sl' => 74.50,
            'latest_close_price' => 78.20,
            'latest_sma_20' => 77.50,
            'latest_atr_14' => 2.80,
            'status' => 'open',
        ]);

        Position::create([
            'ticker' => 'NVDA',
            'entry_price' => 120.00,
            'quantity' => 10,
            'current_sl' => 115.00,
            'latest_close_price' => 125.00,
            'latest_sma_20' => 122.00,
            'latest_atr_14' => 4.50,
            'status' => 'open',
        ]);
    }
}
