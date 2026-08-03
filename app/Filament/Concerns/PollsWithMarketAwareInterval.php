<?php

namespace App\Filament\Concerns;

use App\Support\FilamentPolling;

trait PollsWithMarketAwareInterval
{
    protected function getPollingInterval(): ?string
    {
        return FilamentPolling::interval();
    }
}
