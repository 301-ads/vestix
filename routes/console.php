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
// 22:30 NL ≈ 30 min na US close in CEST (16:00 ET = 22:00) zodat slotkoersen en volume beschikbaar zijn.
Schedule::command('vestix:fetch-data')
    ->weekdays()
    ->dailyAt('22:30')
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

// Order Plan prune → daarna prep digest (zelfde run, gegarandeerde volgorde).
Schedule::command('vestix:order-plan-premarket-prune')
    ->weekdays()
    ->dailyAt(config('vestix.execution_digest.prune_time', '14:30'))
    ->timezone('Europe/Amsterdam')
    ->then(function (): void {
        Artisan::call('vestix:execution-prep-digest');
    });

Schedule::command('vestix:earnings-exit-alerts --phase=warning')
    ->weekdays()
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=action')
    ->weekdays()
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=weekend')
    ->weekends()
    ->dailyAt('09:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=final')
    ->weekdays()
    ->dailyAt('21:30')
    ->timezone('Europe/Amsterdam');

// Gap Reality Check: 1 min na US open (9:30 ET = 15:30 NL → 15:31 NL).
Schedule::command('vestix:execution-order-plan')
    ->weekdays()
    ->dailyAt(config('vestix.execution_digest.time', '15:31'))
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:bankroll-update-reminders')
    ->saturdays()
    ->dailyAt('10:00')
    ->timezone('Europe/Amsterdam');

// IBKR Flex EOD sync: kort na market-data (balances + cashflows; open orders via CP when enabled).
Schedule::command('vestix:sync-ibkr')
    ->weekdays()
    ->dailyAt('22:45')
    ->timezone('Europe/Amsterdam');
