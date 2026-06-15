<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vestix:fetch-data')
    ->weekdays()
    ->dailyAt('22:15')
    ->timezone('America/New_York');

Schedule::command('vestix:watch-scouts')
    ->everyFifteenMinutes()
    ->timezone('Europe/Amsterdam')
    ->between('15:30', '22:00')
    ->weekdays();
