<?php

use App\Jobs\RebuildSquadLeaderboardJob;
use App\Support\UsMarketSession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// EOD sync: alle open posities en scouts (Polygon bars + Finnhub/AV quote).
// 23:00 NL = ~45 min na US close (16:15 ET) zodat slotkoersen en volume beschikbaar zijn.
Schedule::command('vestix:fetch-data')
    ->weekdays()
    ->dailyAt('23:00')
    ->timezone('Europe/Amsterdam');

// Intraday live koersen: elk uur op :05 ET (niet op :00 — voorkomt race met schedule:run).
// Venster-check via when() i.p.v. between(); between() + hourly() sloeg op productie soms runs over.
Schedule::command('vestix:watch-target-prices')
    ->hourlyAt(5)
    ->weekdays()
    ->timezone('America/New_York')
    ->when(fn (): bool => config('vestix.intraday_target_watch.enabled', true)
        && UsMarketSession::isIntradayTargetWatchWindow());

Schedule::job(new RebuildSquadLeaderboardJob)->hourly();

Schedule::command('vestix:send-daily-digests')
    ->weekdays()
    ->dailyAt('21:45')
    ->timezone('Europe/Amsterdam');

// Pre-market gatekeeper: 60 min vóór US open (9:30 ET = 15:30 NL, scan om 14:30 NL = 8:30 ET).
Schedule::command('vestix:premarket-gatekeeper')
    ->weekdays()
    ->dailyAt(config('vestix.premarket.gatekeeper_time', '14:30'))
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=warning')
    ->weekdays()
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=action')
    ->weekdays()
    ->dailyAt('15:00')
    ->timezone('Europe/Amsterdam');

// Buy-stop reminder: 5 min na US open (9:30 ET = 15:30 NL, reminder om 15:35 NL).
Schedule::command('vestix:market-open-buy-stop-reminders')
    ->weekdays()
    ->dailyAt(config('vestix.market_open_reminder.time', '15:35'))
    ->timezone('Europe/Amsterdam');
