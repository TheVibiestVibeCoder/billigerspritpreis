<?php

use App\Jobs\FetchPricesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new FetchPricesJob())
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::job(new FetchPricesJob())
    ->cron('2 12 * * 1,3,5')
    ->withoutOverlapping();
