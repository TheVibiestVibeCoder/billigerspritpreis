<?php

namespace App\Jobs;

use App\Services\EControlService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchPricesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function handle(EControlService $eControlService): void
    {
        $eControlService->warmUp(includeClosed: false);
    }
}
