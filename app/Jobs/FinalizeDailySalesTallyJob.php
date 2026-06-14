<?php

namespace App\Jobs;

use App\Jobs\Middleware\MeasureJobPerformance;
use App\Models\DailySalesSummary;
use App\Models\DailySalesTallyChunk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Task 4 finalize: merge partial chunk rows into daily_sales_summaries.
 */
class FinalizeDailySalesTallyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $saleDate,
        public string $batchId,
    ) {}

    /** @return array<int, class-string> */
    public function middleware(): array
    {
        return [MeasureJobPerformance::class];
    }

    public function handle(): void
    {
        $orderCount = (int) DailySalesTallyChunk::query()
            ->where('batch_id', $this->batchId)
            ->sum('order_count');

        $totalQuantity = (int) DailySalesTallyChunk::query()
            ->where('batch_id', $this->batchId)
            ->sum('total_quantity');

        DailySalesSummary::query()->updateOrCreate(
            ['sale_date' => $this->saleDate],
            [
                'successful_order_count' => $orderCount,
                'total_quantity' => $totalQuantity,
                'processing_mode' => (string) config('daily_sales_tally.processing_mode_concurrent', 'queued_batched_concurrent'),
                'computed_at' => now(),
            ]
        );
    }
}
