<?php

namespace App\Http\Controllers;

use App\Http\Requests\TallyDailySalesRequest;
use App\Jobs\ProcessDailySalesTallyJob;
use App\Models\DailySalesSummary;
use App\Models\Order;
use Illuminate\Http\JsonResponse;

class DailySalesTallyController extends Controller
{
    /**
     * Before improvement: full tally on the HTTP thread (same process as the web request).
     *
     * Uses a cursor() loop on the orders query so each row is handled
     * in turn instead of get(), which builds one huge Collection and exhausts PHP memory on
     * hundreds of thousands of orders. The trade-off you still demonstrate: the client waits
     * until every row for the day is scanned here; the queued path returns immediately.
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
     * After improvement: push ProcessDailySalesTallyJob to the queue. Run `php artisan queue:work`
     * to process it; totals appear in GET /api/daily-sales-summary after the job finishes.
     */
    public function tallyQueued(TallyDailySalesRequest $request): JsonResponse
    {
        $saleDate = $request->validated('sale_date');

        // ProcessDailySalesTallyJob::dispatch($saleDate);
        ProcessDailySalesTallyJob::dispatchSync($saleDate);
        
        $row = DailySalesSummary::query()->whereDate('sale_date', $saleDate)->firstOrFail();

        return response()->json([
            'message' => 'Tally completed using batched job processing.',
            'processing_mode' => 'queued_batched',
            'sale_date' => $saleDate,
            'successful_order_count' => (int) $row->successful_order_count,
            'total_quantity' => (int) $row->total_quantity,
            'computed_at' => $row->computed_at?->toIso8601String(),
            ]);
        // return response()->json([
        //     'message' => 'Tally job queued. Run `php artisan queue:work`, then GET /api/daily-sales-summary for results.',
        //     'processing_mode' => 'queued_batched',
        //     'sale_date' => $saleDate,
        // ]);
    }

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
