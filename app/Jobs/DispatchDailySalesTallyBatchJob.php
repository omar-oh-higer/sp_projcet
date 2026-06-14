<?php

namespace App\Jobs;

use App\Jobs\Middleware\MeasureJobPerformance;
use App\Services\DailySalesTally\DailySalesTallyBatchOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Task 4 coordinator: builds chunk jobs and submits a Bus::batch for parallel workers.
 */
class DispatchDailySalesTallyBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $saleDate,
    ) {}

    /** @return array<int, class-string> */
    public function middleware(): array
    {
        return [MeasureJobPerformance::class];
    }

    public function handle(DailySalesTallyBatchOrchestrator $orchestrator): void
    {
        $orchestrator->start($this->saleDate);
    }
}
