<?php

namespace App\Http\Controllers;

use App\Http\Requests\TallyDailySalesRequest;
use App\Models\DailySalesSummary;
use App\Models\DailySalesTallyChunk;
use App\Models\Order;
use App\Services\DailySalesTally\DailySalesTallyBatchOrchestrator;
use App\Services\DailySalesTally\TallyBatchStatusBuilder;
use App\Services\DailySalesTally\TallyChunkProgressTracker;
use App\Services\DailySalesTally\TallyDemoOrderSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Demo helper: seed successful orders for a sale_date (defaults to today).
     * Used by /demo Task 4 — same logic as BulkOrdersForTallyDemoSeeder.
     */
    public function seedDemoOrders(Request $request, TallyDemoOrderSeeder $seeder): JsonResponse
    {
        $payload = $request->validate([
            'sale_date' => ['nullable', 'date_format:Y-m-d'],
            'count' => ['nullable', 'integer', 'min:1'],
            'clear_existing' => ['nullable', 'boolean'],
        ]);

        $saleDate = $payload['sale_date'] ?? now()->toDateString();
        $defaultCount = (int) config('daily_sales_tally.demo_seed_count', 2500);
        $maxCount = (int) config('daily_sales_tally.demo_seed_max', 10_000);
        $count = min((int) ($payload['count'] ?? $defaultCount), $maxCount);
        $clearExisting = (bool) ($payload['clear_existing'] ?? false);

        try {
            $result = $seeder->seed($saleDate, $count, $clearExisting);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $chunkSize = max((int) config('daily_sales_tally.chunk_size', 500), 1);
        $expectedChunks = (int) ceil($result['orders_for_date'] / $chunkSize);

        return response()->json([
            'message' => "Seeded {$result['inserted']} orders for {$result['sale_date']}.",
            'sale_date' => $result['sale_date'],
            'inserted' => $result['inserted'],
            'orders_for_date' => $result['orders_for_date'],
            'chunk_size' => $chunkSize,
            'expected_chunks_if_tally_now' => $expectedChunks,
            'note' => 'Use sale_date in tally-queued; ensure queue workers are running.',
        ]);
    }

    /**
     * Demo helper: chunk partial rows + merged summary for visualizing parallel workers.
     */
    public function batchStatus(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'sale_date' => ['required', 'date_format:Y-m-d'],
            'batch_id' => ['nullable', 'string', 'max:36'],
            'expected_chunks' => ['nullable', 'integer', 'min:0'],
            'latest' => ['nullable', 'boolean'],
        ]);

        $saleDate = $payload['sale_date'];
        $batchId = $payload['batch_id'] ?? null;
        $expectedChunks = isset($payload['expected_chunks']) ? (int) $payload['expected_chunks'] : null;
        $useLatest = (bool) ($payload['latest'] ?? false);

        if ($useLatest || $batchId === null || $batchId === '') {
            $batchId = DailySalesTallyChunk::query()
                ->whereDate('sale_date', $saleDate)
                ->orderByDesc('id')
                ->value('batch_id');
        }

        $ordersInDb = Order::query()
            ->whereDate('created_at', $saleDate)
            ->where('status', 'success')
            ->count();

        $chunkQuery = DailySalesTallyChunk::query()
            ->whereDate('sale_date', $saleDate)
            ->orderBy('chunk_index');

        if ($batchId !== null && $batchId !== '') {
            $chunkQuery->where('batch_id', $batchId);
        }

        $completedRows = $chunkQuery->get();
        $chunks = $completedRows->map(fn (DailySalesTallyChunk $row) => [
            'chunk_index' => $row->chunk_index,
            'batch_id' => $row->batch_id,
            'order_count' => $row->order_count,
            'total_quantity' => $row->total_quantity,
            'worker_pid' => $row->worker_pid,
            'worker_terminal' => $row->worker_terminal,
            'updated_at' => $row->updated_at?->toIso8601String(),
        ])->values()->all();

        $partialOrderCount = (int) $completedRows->sum('order_count');
        $partialQuantity = (int) $completedRows->sum('total_quantity');
        $completedChunks = $completedRows->count();

        if ($expectedChunks === null) {
            $expectedChunks = app(DailySalesTallyBatchOrchestrator::class)->expectedChunkCount($saleDate);
        }

        if ($batchId !== null && $completedChunks > 0) {
            $expectedChunks = max($expectedChunks, $completedChunks);
        }

        $summaryRow = DailySalesSummary::query()->whereDate('sale_date', $saleDate)->first();
        $summary = $summaryRow ? [
            'sale_date' => $summaryRow->sale_date->format('Y-m-d'),
            'successful_order_count' => $summaryRow->successful_order_count,
            'total_quantity' => $summaryRow->total_quantity,
            'processing_mode' => $summaryRow->processing_mode,
            'computed_at' => $summaryRow->computed_at?->toIso8601String(),
        ] : null;

        $chunkSize = max((int) config('daily_sales_tally.chunk_size', 500), 1);
        $maxConcurrent = max((int) config('daily_sales_tally.max_concurrent_chunks', 5), 1);
        $demoWorkerCount = max((int) config('daily_sales_tally.demo_worker_count', 4), 1);

        $runningRows = ($batchId !== null && $batchId !== '' && $expectedChunks > 0)
            ? TallyChunkProgressTracker::runningForBatch($batchId, $expectedChunks)
            : [];

        $slotView = app(TallyBatchStatusBuilder::class)->buildSlots(
            batchId: (string) ($batchId ?? ''),
            expectedChunks: $expectedChunks,
            completedRows: $completedRows,
            runningRows: $runningRows,
            maxConcurrentChunks: $maxConcurrent,
            chunkSize: $chunkSize,
            demoWorkerCount: $demoWorkerCount,
        );

        $summaryMatchesBatch = $summary !== null
            && $expectedChunks > 0
            && $completedChunks >= $expectedChunks
            && $partialOrderCount === ($summary['successful_order_count'] ?? -1);

        return response()->json([
            'sale_date' => $saleDate,
            'batch_id' => $batchId,
            'chunk_size' => $chunkSize,
            'orders_in_db_for_date' => $ordersInDb,
            'expected_chunks' => $expectedChunks,
            'completed_chunks' => $completedChunks,
            'progress_percent' => $expectedChunks > 0
                ? min(100, (int) round(($completedChunks / $expectedChunks) * 100))
                : ($summaryMatchesBatch ? 100 : 0),
            'partial_totals' => [
                'order_count' => $partialOrderCount,
                'total_quantity' => $partialQuantity,
            ],
            'chunks' => $chunks,
            'chunk_slots' => $slotView['chunk_slots'],
            'worker_processes' => $slotView['worker_processes'],
            'queue_terminals' => $slotView['queue_terminals'],
            'lecture_note_en' => $slotView['lecture_note_en'],
            'lecture_note_ar' => $slotView['lecture_note_ar'],
            'active_worker_count' => $slotView['active_worker_count'],
            'distinct_worker_count' => $slotView['distinct_worker_count'],
            'max_concurrent_chunks' => $slotView['max_concurrent_chunks'],
            'demo_worker_count' => $slotView['demo_worker_count'],
            'double_duty_worker_number' => $slotView['double_duty_worker_number'],
            'worker_tracking_ok' => $slotView['worker_tracking_ok'],
            'worker_tracking_hint_en' => $slotView['worker_tracking_hint_en'],
            'worker_tracking_hint_ar' => $slotView['worker_tracking_hint_ar'],
            'demo_chunk_delay_seconds' => (float) config('daily_sales_tally.demo_chunk_delay_seconds', 0),
            'running_chunks' => count($runningRows),
            'summary' => $summaryMatchesBatch ? $summary : null,
            'previous_summary' => ($summary !== null && ! $summaryMatchesBatch) ? $summary : null,
            'finalize_ready' => $summaryMatchesBatch,
            'finalize_pending' => ! $summaryMatchesBatch
                && $expectedChunks > 0
                && $completedChunks >= $expectedChunks,
            'chunks_match_orders' => $summaryMatchesBatch,
            'waiting_for_workers' => $batchId !== null
                && $expectedChunks > 0
                && $completedChunks < $expectedChunks,
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }
}
