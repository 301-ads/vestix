<?php

namespace App\Support\Kluis;

use App\Enums\KluisClimate;

readonly class KluisThermometerReading
{
    public function __construct(
        public KluisClimate $climate,
        public float $deviationPct,
        public float $close,
        public float $sma200,
        public string $ticker,
        public ?string $resolvedSymbol = null,
    ) {}

    public function message(): string
    {
        return $this->climate->orderMessage($this->deviationPct);
    }

    /**
     * True when climate bars come from a different symbol than the display ticker
     * (e.g. VWCE climate via US proxy VT).
     */
    public function usesProxy(): bool
    {
        if ($this->resolvedSymbol === null || trim($this->resolvedSymbol) === '') {
            return false;
        }

        return strtoupper($this->resolvedSymbol) !== strtoupper($this->ticker);
    }

    /**
     * Currency for thermometer close/SMA — USD for bare US proxies, EUR for EU listings.
     */
    public function priceCurrencySymbol(): string
    {
        $symbol = strtoupper($this->resolvedSymbol ?? $this->ticker);

        // Exchange-suffixed EU symbols (VWCE.DE, IWDA.AS, …) are EUR.
        if (str_contains($symbol, '.')) {
            return '€';
        }

        // Bare US proxy tickers (VT) are USD; same-ticker EU ETF bars stay EUR.
        return $this->usesProxy() ? '$' : '€';
    }

    /**
     * One-line card hint: which market the SMA-200 climate uses (not holdings MTM).
     */
    public function priceLine(): string
    {
        $ccy = $this->priceCurrencySymbol();
        $label = $this->usesProxy()
            ? sprintf('%s via %s', $this->ticker, strtoupper((string) $this->resolvedSymbol))
            : ($this->resolvedSymbol ? strtoupper($this->resolvedSymbol) : $this->ticker);

        return sprintf(
            '%s · koers %s%s · SMA-200 %s%s',
            $label,
            $ccy,
            number_format($this->close, 2, ',', '.'),
            $ccy,
            number_format($this->sma200, 2, ',', '.'),
        );
    }
}
