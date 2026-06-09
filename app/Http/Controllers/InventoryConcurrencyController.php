<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Services\ConcurrencyControl\ConcurrencyControlMetrics;
use App\Services\ConcurrencyControl\DistributedLockStockPurchaseService;
use App\Services\ConcurrencyControl\OptimisticStockPurchaseService;
use Illuminate\Http\JsonResponse;

/** Task 7: optimistic locking vs distributed Redis lock + pessimistic DB purchase. */
class InventoryConcurrencyController extends Controller
{
    /**
     * Before (Session 7): optimistic locking — assumes low conflict; fails on version mismatch.
     */
    public function buyOptimistic(
        PurchaseRequest $request,
        OptimisticStockPurchaseService $optimisticStockPurchaseService,
    ): JsonResponse {
        $payload = $request->validated();
        $delayMs = max((int) $request->header('X-DEMO-DELAY-MS', 0), 0);

        $result = $optimisticStockPurchaseService->purchase(
            productId: $payload['product_id'],
            quantity: $payload['quantity'],
            userId: $request->user()?->id,
            delayMs: $delayMs,
        );

        if ($result['status'] === 'product_not_found') {
            return response()->json([
                'message' => 'Product not found',
                'concurrency_strategy' => 'optimistic',
            ], 404);
        }

        if ($result['status'] === 'insufficient_stock') {
            return response()->json([
                'message' => 'Insufficient stock',
                'concurrency_strategy' => 'optimistic',
                'conflict' => false,
                'stock' => $result['stock'],
                'version' => $result['version'],
            ], 409);
        }

        if ($result['status'] === 'version_conflict') {
            return response()->json([
                'message' => 'Optimistic version conflict — another process updated inventory first.',
                'concurrency_strategy' => 'optimistic',
                'conflict' => true,
                'stock' => $result['stock'],
                'version' => $result['version'],
            ], 409);
        }

        return response()->json([
            'message' => 'Purchased with optimistic locking',
            'concurrency_strategy' => 'optimistic',
            'conflict' => false,
            'stock' => $result['stock'],
            'order_id' => $result['order_id'],
            'version' => $result['version'],
        ]);
    }

    /**
     * After (Session 7): cluster-wide Redis lock, then pessimistic DB transaction (StockPurchaseService).
     */
    public function buyDistributedLock(
        PurchaseRequest $request,
        DistributedLockStockPurchaseService $distributedLockStockPurchaseService,
    ): JsonResponse {
        $payload = $request->validated();

        $result = $distributedLockStockPurchaseService->purchase(
            productId: $payload['product_id'],
            quantity: $payload['quantity'],
            userId: $request->user()?->id,
        );

        if ($result['status'] === 'lock_timeout') {
            return response()->json([
                'message' => 'Could not acquire distributed inventory lock in time',
                'concurrency_strategy' => 'distributed_pessimistic',
                'lock_acquired' => false,
            ], 503);
        }

        if ($result['status'] === 'product_not_found') {
            return response()->json([
                'message' => 'Product not found',
                'concurrency_strategy' => 'distributed_pessimistic',
                'lock_acquired' => true,
            ], 404);
        }

        if ($result['status'] === 'insufficient_stock') {
            return response()->json([
                'message' => 'Insufficient stock',
                'concurrency_strategy' => 'distributed_pessimistic',
                'lock_acquired' => true,
                'stock' => $result['stock'],
            ], 409);
        }

        return response()->json([
            'message' => 'Purchased with distributed lock + pessimistic DB transaction',
            'concurrency_strategy' => 'distributed_pessimistic',
            'lock_acquired' => true,
            'stock' => $result['stock'],
            'order_id' => $result['order_id'],
        ]);
    }

    public function stats(ConcurrencyControlMetrics $metrics): JsonResponse
    {
        return response()->json([
            'message' => 'Concurrency control demo metrics (Session 7).',
            'lock_store' => config('inventory_locking.lock_store', 'redis'),
            'metrics' => $metrics->snapshot(),
        ]);
    }

    public function reset(ConcurrencyControlMetrics $metrics): JsonResponse
    {
        $metrics->reset();

        return response()->json([
            'message' => 'Concurrency control demo metrics reset.',
        ]);
    }
}
