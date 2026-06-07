<?php

namespace App\Jobs;

use App\Jobs\Middleware\MeasureJobPerformance;
use App\Models\DailySalesSummary;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Task 4 “after”: tally successful orders for one calendar day using chunkById so memory stays
 * bounded; writes one row to daily_sales_summaries.
 */
class ProcessDailySalesTallyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $chunkSize;

    /**
     * @param  string  $saleDate  Day to tally (`Y-m-d`), matched with `whereDate(created_at, …)`
     * @param  int|null  $chunkSize  Rows per `chunkById` batch (default 500)
     */
    public function __construct(
        public string $saleDate,
        ?int $chunkSize = null,
    ) {
        $this->chunkSize = $chunkSize ?? 500;
    }

    /** @return array<int, class-string> */
    public function middleware(): array
    {
        return [MeasureJobPerformance::class];
    }

    /** Sum quantities and order count for the day, then upsert the `daily_sales_summaries` row. */
    public function handle(): void
    {
        $totalQuantity = 0;
        $orderCount = 0;

        Order::query()
            ->whereDate('created_at', $this->saleDate)
            ->where('status', 'success')
            ->orderBy('id')
            ->chunkById($this->chunkSize, function ($orders) use (&$totalQuantity, &$orderCount): void {
                foreach ($orders as $order) {
                    $totalQuantity += $order->quantity;
                    $orderCount++;
                }
            });

        DailySalesSummary::query()->updateOrCreate(
            ['sale_date' => $this->saleDate],
            [
                'successful_order_count' => $orderCount,
                'total_quantity' => $totalQuantity,
                'processing_mode' => 'queued_batched',
                'computed_at' => now(),
            ]
        );
    }
}
