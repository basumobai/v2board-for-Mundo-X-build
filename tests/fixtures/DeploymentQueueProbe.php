<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

// Copied into the disposable CI checkout only, never installed in app/.
class DeploymentQueueProbe implements ShouldQueue
{
    public function handle(): void
    {
        file_put_contents(storage_path('app/deployment-queue-probe'), 'processed');
    }
}
