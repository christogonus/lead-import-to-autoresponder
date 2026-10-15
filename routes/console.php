<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('deliveries:drip')
    ->everyMinute()
    ->withoutOverlapping();

// Erases deleted teams, and every contact they held, once their retention
// window has passed. The privacy policy promises this, so it must run.
Schedule::command('model:prune')
    ->daily()
    ->withoutOverlapping();
