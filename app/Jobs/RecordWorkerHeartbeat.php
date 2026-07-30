<?php

namespace App\Jobs;

use App\Support\OperationalHealth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordWorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(OperationalHealth $health): void
    {
        $health->recordWorkerHeartbeat();
    }
}
