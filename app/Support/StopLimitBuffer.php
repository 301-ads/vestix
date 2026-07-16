<?php

namespace App\Support;

class StopLimitBuffer
{
    public static function bufferFor(float $stopPrice): float
    {
        if ($stopPrice <= 0) {
            return 0.0;
        }

        /** @var list<array{max_price: float|null, buffer: float|int|string}> $tiers */
        $tiers = config('vestix.stop_limit_buffer.tiers', [
            ['max_price' => 20.0, 'buffer' => 0.05],
            ['max_price' => 50.0, 'buffer' => 0.10],
            ['max_price' => 100.0, 'buffer' => 0.15],
            ['max_price' => null, 'buffer' => 0.25],
        ]);

        foreach ($tiers as $tier) {
            $maxPrice = $tier['max_price'];
            $buffer = (float) $tier['buffer'];

            if ($maxPrice === null) {
                return round($buffer, 2);
            }

            if ($stopPrice < (float) $maxPrice) {
                return round($buffer, 2);
            }
        }

        return 0.25;
    }

    public static function limitPrice(float $stopPrice): float
    {
        if ($stopPrice <= 0) {
            return 0.0;
        }

        return round($stopPrice + self::bufferFor($stopPrice), 2);
    }
}
