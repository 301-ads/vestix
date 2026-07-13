<?php

namespace App\Filament\Concerns;

use App\Models\Position;
use App\Support\MarketDataFreshness;

trait PollsPositionMarketData
{
    public bool $pollPositionMarketData = false;

    public function startPollingPositionMarketData(): void
    {
        $this->pollPositionMarketData = true;
    }

    public function pollPositionMarketDataFetch(): void
    {
        if (! $this->pollPositionMarketData) {
            return;
        }

        $record = $this->getRecord();

        if (! $record instanceof Position) {
            $this->pollPositionMarketData = false;

            return;
        }

        if (MarketDataFreshness::isPositionSyncInProgress($record->id)) {
            return;
        }

        $this->pollPositionMarketData = false;
        $record->refresh();
        $record->load('asset');

        $this->refreshFormData([
            'latest_open_price',
            'latest_close_price',
            'latest_sma_20',
            'sma_20_five_days_ago',
            'sma_20_ten_days_ago',
            'latest_sma_50',
            'latest_atr_14',
            'scout_rsi',
            'bounce_volume_above_average',
            'bounce_day_volume',
            'avg_volume_30d',
            'relative_volume',
            'volume_sma_20',
            'sector_etf',
            'sector_close',
            'sector_sma_50',
            'sector_trend_positive',
            'pre_bounce_extension_atr',
            'asset_earnings_date_override',
            'asset_earnings_hour_override',
        ]);
    }
}
