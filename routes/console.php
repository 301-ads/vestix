<?php

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
