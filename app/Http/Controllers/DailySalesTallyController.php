<?php

namespace App\Http\Controllers;

use App\Http\Requests\TallyDailySalesRequest;
use App\Models\DailySalesSummary;
use App\Models\Order;
use App\Services\DailySalesTally\DailySalesTallyBatchOrchestrator;
use Illuminate\Http\JsonResponse;

/** Task 4 API: inline daily tally vs queued batched tally + read stored summary. */
class DailySalesTallyController extends Controller
{
    /**
     * Before improvement: full tally on the HTTP thread (same process as the web request).
     *
     * Uses a cursor() loop on the orders query so each row is handled
     * in turn instead of get(), which builds one huge Collection and exhausts PHP memory on
     * hundreds of thousands of orders. The HTTP response waits until the scan finishes.
     * The queued tally endpoint returns immediately and uses a worker instead.
     */
    public function tallyWait(TallyDailySalesRequest $request): JsonResponse
    {
        $saleDate = $request->validated('sale_date');

        $memoryLimit = config('bulk_orders.tally_wait_memory_limit');
        if (is_string($memoryLimit) && $memoryLimit !== '') {
            @ini_set('memory_limit', $memoryLimit);
        }

        $totalQuantity = 0;
        $orderCount = 0;

        $query = Order::query()
            ->whereDate('created_at', $saleDate)
            ->where('status', 'success')
            ->orderBy('id');

        foreach ($query->cursor() as $order) {
            $totalQuantity += $order->quantity;
            $orderCount++;
        }

        DailySalesSummary::query()->updateOrCreate(
            ['sale_date' => $saleDate],
            [
                'successful_order_count' => $orderCount,
                'total_quantity' => $totalQuantity,
                'processing_mode' => 'inline_unbatched',
                'computed_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Tally completed on the request thread (full day scan in-process; not queued).',
            'processing_mode' => 'inline_unbatched',
            'sale_date' => $saleDate,
            'successful_order_count' => $orderCount,
            'total_quantity' => $totalQuantity,
        ]);
    }

    /**
     * Task 4 “after”: concurrent batch via Bus::batch — chunk jobs run in parallel on queue workers.
     * Uses Task 3 (queue/thread pool) + Task 2 (max concurrent chunks semaphore).
     */
    public function tallyQueued(
        TallyDailySalesRequest $request,
        DailySalesTallyBatchOrchestrator $orchestrator,
    ): JsonResponse {
        $saleDate = $request->validated('sale_date');

        $result = $orchestrator->start($saleDate);

        return response()->json([
            'message' => 'Tally batch queued. Chunk jobs will run in parallel on queue workers.',
            'processing_mode' => config('daily_sales_tally.processing_mode_concurrent', 'queued_batched_concurrent'),
            'sale_date' => $saleDate,
            'expected_chunks' => $result['expected_chunks'],
            'batch_id' => $result['batch_id'],
            'concurrency_note' => 'Run multiple queue:work processes (thread pool from Task 3). Chunk concurrency capped by Task 2 semaphore.',
        ]);
    }

    /** Return the stored DailySalesSummary row for the given sale_date (query string). */
    public function showSummary(TallyDailySalesRequest $request): JsonResponse
    {
        $saleDate = $request->validated('sale_date');
        $row = DailySalesSummary::query()->whereDate('sale_date', $saleDate)->first();

        if (! $row) {
            return response()->json([
                'message' => 'No summary for this date yet. Run tally-wait or tally-queued (and worker) first.',
                'sale_date' => $saleDate,
            ], 404);
        }

        return response()->json([
            'sale_date' => $row->sale_date->format('Y-m-d'),
            'successful_order_count' => $row->successful_order_count,
            'total_quantity' => $row->total_quantity,
            'processing_mode' => $row->processing_mode,
            'computed_at' => $row->computed_at?->toIso8601String(),
        ]);
    }
}
