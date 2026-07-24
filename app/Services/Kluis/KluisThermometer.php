<?php

namespace App\Services\Kluis;

use App\Enums\KluisClimate;
use App\Models\VaultSetting;
use App\Support\Kluis\KluisThermometerReading;

class KluisThermometer
{
    public function classify(
        float $deviationPct,
        float $overheatThresholdPct = 10.0,
        float $crashThresholdPct = 10.0,
    ): KluisClimate {
        $overheat = abs($overheatThresholdPct);
        $crash = abs($crashThresholdPct);

        if ($deviationPct > $overheat) {
            return KluisClimate::Overheat;
        }

        if ($deviationPct < -$crash) {
            return KluisClimate::Crash;
        }

        if ($deviationPct < 0) {
            return KluisClimate::Dip;
        }

        return KluisClimate::Neutral;
    }

    public function readingFromPrices(
        float $close,
        float $sma200,
        string $ticker,
        VaultSetting $settings,
        ?string $resolvedSymbol = null,
    ): KluisThermometerReading {
        $deviationPct = $sma200 == 0.0
            ? 0.0
            : (($close - $sma200) / $sma200) * 100;

        $climate = $this->classify(
            $deviationPct,
            (float) $settings->overheat_threshold_pct,
            (float) $settings->crash_threshold_pct,
        );

        return new KluisThermometerReading(
            climate: $climate,
            deviationPct: round($deviationPct, 4),
            close: round($close, 4),
            sma200: round($sma200, 4),
            ticker: $ticker,
            resolvedSymbol: $resolvedSymbol,
        );
    }
}
