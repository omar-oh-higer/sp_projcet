<?php

namespace App\Jobs;

use App\Jobs\Middleware\LimitConcurrentTallyChunks;
use App\Jobs\Middleware\MeasureJobPerformance;
use App\Models\DailySalesTallyChunk;
use App\Models\Order;
use App\Services\DailySalesTally\TallyChunkProgressTracker;
use App\Services\DailySalesTally\TallyWorkerRegistry;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Task 4 parallel worker: sums one chunk of order IDs and stores a partial row.
 */
class ProcessDailySalesChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(
        public string $saleDate,
        public array $orderIds,
        public int $chunkIndex,
    ) {}

    /** @return array<int, class-string> */
    public function middleware(): array
    {
        return [
            LimitConcurrentTallyChunks::class,
            MeasureJobPerformance::class,
        ];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->orderIds === []) {
            return;
        }

        $batchId = (string) $this->batch()->id;
        $workerPid = (int) (getmypid() ?: 0);
        $workerTerminal = TallyWorkerRegistry::terminalForProcess($batchId, $workerPid);

        TallyChunkProgressTracker::markRunning($batchId, $this->chunkIndex, $workerPid, $workerTerminal);

        $delaySeconds = (float) config('daily_sales_tally.demo_chunk_delay_seconds', 0);
        if ($delaySeconds > 0) {
            usleep((int) round($delaySeconds * 1_000_000));
        }

        try {
            $rows = Order::query()
                ->whereIn('id', $this->orderIds)
                ->whereDate('created_at', $this->saleDate)
                ->where('status', 'success')
                ->get(['quantity']);

            $orderCount = $rows->count();
            $totalQuantity = (int) $rows->sum('quantity');

            DailySalesTallyChunk::query()->updateOrCreate(
                [
                    'batch_id' => $batchId,
                    'chunk_index' => $this->chunkIndex,
                ],
                [
                    'sale_date' => $this->saleDate,
                    'order_count' => $orderCount,
                    'total_quantity' => $totalQuantity,
                    'worker_pid' => $workerPid > 0 ? $workerPid : null,
                    'worker_terminal' => $workerTerminal > 0 ? $workerTerminal : null,
                ]
            );
        } finally {
            TallyChunkProgressTracker::clearRunning($batchId, $this->chunkIndex);
        }
    }
}
