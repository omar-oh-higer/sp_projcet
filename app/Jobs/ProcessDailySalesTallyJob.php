<?php

namespace App\Jobs;

use App\Models\DailySalesSummary;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDailySalesTallyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $chunkSize;

    public function __construct(
        public string $saleDate,
        ?int $chunkSize = null,
    ) {
        $this->chunkSize = $chunkSize ?? 500;
    }

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
