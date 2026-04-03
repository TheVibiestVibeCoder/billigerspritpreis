<?php

use App\Jobs\FetchPricesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('test {--filter=} {--stop-on-failure} {--parallel}', function () {
    $pestBinary = base_path('vendor/pestphp/pest/bin/pest');

    if (! file_exists($pestBinary)) {
        $this->error('Pest is not installed in this environment.');
        $this->line('Run `composer install` without `--no-dev` to install test dependencies.');

        return self::FAILURE;
    }

    $command = [PHP_BINARY, $pestBinary, '--colors=always'];

    if ($filter = $this->option('filter')) {
        $command[] = '--filter='.$filter;
    }

    if ((bool) $this->option('stop-on-failure')) {
        $command[] = '--stop-on-failure';
    }

    if ((bool) $this->option('parallel')) {
        $command[] = '--parallel';
    }

    $process = new Process($command, base_path());
    $process->setTimeout(null);

    return $process->run(function (string $type, string $buffer): void {
        $this->output->write($buffer);
    });
})->purpose('Run the application test suite via Pest');

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
