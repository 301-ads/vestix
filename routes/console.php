<?php

use App\Jobs\RebuildSquadLeaderboardJob;
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

Schedule::job(new RebuildSquadLeaderboardJob)->hourly();

Schedule::command('vestix:send-daily-digests')
    ->weekdays()
    ->dailyAt('21:45')
    ->timezone('Europe/Amsterdam');

// Pre-market gatekeeper: 30 min vóór US open (9:30 ET = 15:30 NL, check om 15:00 NL = 9:00 ET).
Schedule::command('vestix:premarket-gatekeeper')
    ->weekdays()
    ->dailyAt('15:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=warning')
    ->weekdays()
    ->dailyAt('08:00')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:earnings-exit-alerts --phase=action')
    ->weekdays()
    ->dailyAt('15:00')
    ->timezone('Europe/Amsterdam');
