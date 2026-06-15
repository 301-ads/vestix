<?php

namespace App\Filament\Concerns;

use App\Support\MarketDataFreshness;

trait PollsTickerMarketData
{
    public bool $pollTickerMarketData = false;

    public ?string $pollingTicker = null;

    public function startPollingTickerMarketData(string $ticker): void
    {
        $this->pollTickerMarketData = true;
        $this->pollingTicker = strtoupper(trim($ticker));
    }

    public function pollTickerMarketDataFetch(): void
    {
        if (! $this->pollTickerMarketData || $this->pollingTicker === null) {
            return;
        }

        $userId = auth()->id();

        if ($userId === null) {
            $this->pollTickerMarketData = false;

            return;
        }

        if (MarketDataFreshness::isTickerSyncInProgress($userId, $this->pollingTicker)) {
            return;
        }

        $data = MarketDataFreshness::pullTickerFetchResult($userId, $this->pollingTicker);

        $this->pollTickerMarketData = false;
        $this->pollingTicker = null;

        if ($data === null) {
            return;
        }

        $this->applyTickerMarketDataToForm($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function applyTickerMarketDataToForm(array $data): void
    {
        $state = $this->form->getRawState();
        $fill = array_merge($state, $data);

        if (method_exists($this, 'mutateTickerMarketDataFill')) {
            $fill = $this->mutateTickerMarketDataFill($fill, $data);
        }

        $this->form->fill($fill);
    }
}
