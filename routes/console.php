<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('vestix:fetch-data')
    ->weekdays()
    ->dailyAt('22:05')
    ->timezone('Europe/Amsterdam');

Schedule::command('vestix:fetch-data --pre-close')
    ->weekdays()
    ->dailyAt('21:50')
    ->timezone('Europe/Amsterdam');
