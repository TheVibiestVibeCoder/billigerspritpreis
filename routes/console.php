<?php

use App\Jobs\FetchPricesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pre-warm the station price cache every 12 minutes.
// ECONTROL_CACHE_TTL is set to 1200s (20 min), giving an 8-minute buffer so the
// cache is always refreshed before it expires — no user ever hits a cold cache.
//
// IMPORTANT for shared hosting: set QUEUE_CONNECTION=sync in your .env so this job
// runs immediately when the scheduler dispatches it. Without a queue worker, jobs
// queued to the "database" driver will never execute.
Schedule::job(new FetchPricesJob())
    ->cron('*/12 * * * *')
    ->withoutOverlapping();
