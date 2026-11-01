<?php

namespace App\Jobs;

use App\Services\Credits\AdminGrantBatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAdminGrantBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $batchId) {}

    public function handle(AdminGrantBatchService $service): void
    {
        $service->processChunk($this->batchId);
    }
}
