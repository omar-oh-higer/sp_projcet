<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseRequest;
use App\Models\Product;
use App\Services\ConcurrencyControl\ConcurrencyControlMetrics;
use App\Services\ConcurrencyControl\ConcurrencyControlStatusBuilder;
use App\Services\ConcurrencyControl\DistributedLockStockPurchaseService;
use App\Services\ConcurrencyControl\OptimisticStockPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function stats(
        ConcurrencyControlMetrics $metrics,
        ConcurrencyControlStatusBuilder $statusBuilder,
        Request $request,
    ): JsonResponse {
        $productId = $request->integer('product_id') ?: null;
        $enriched = $statusBuilder->build($metrics, $productId);

        return response()->json(array_merge([
            'message' => 'Concurrency control demo metrics (Session 7).',
            'lock_store' => config('inventory_locking.lock_store', 'redis'),
            'metrics' => $metrics->snapshot(),
            'demo_stock' => (int) config('inventory_locking.demo_stock', 10),
            'demo_burst_count' => (int) config('inventory_locking.demo_burst_count', 10),
            'demo_request_delay_ms' => (int) config('inventory_locking.demo_request_delay_ms', 400),
            'demo_optimistic_delay_ms' => (int) config('inventory_locking.demo_optimistic_delay_ms', 50),
        ], $enriched));
    }

    public function reset(ConcurrencyControlMetrics $metrics): JsonResponse
    {
        $metrics->reset();

        return response()->json([
            'message' => 'Concurrency control demo metrics reset.',
        ]);
    }

    public function demoReset(Request $request, ConcurrencyControlMetrics $metrics): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $demoStock = (int) config('inventory_locking.demo_stock', 10);
        $resetMetrics = $request->boolean('reset_metrics', true);

        $product = Product::query()->find($productId);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        $product->update([
            'stock' => $demoStock,
            'version' => 0,
        ]);

        if ($resetMetrics) {
            $metrics->reset();
        }

        return response()->json([
            'message' => $resetMetrics
                ? 'Demo product stock restored and concurrency metrics reset.'
                : 'Demo product stock restored (metrics kept for scenario log).',
            'product_id' => $productId,
            'stock' => $demoStock,
            'version' => 0,
            'metrics_reset' => $resetMetrics,
        ]);
    }

    public function demoStress(
        Request $request,
        OptimisticStockPurchaseService $optimistic,
        DistributedLockStockPurchaseService $distributed,
        ConcurrencyControlMetrics $metrics,
    ): JsonResponse {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $strategy = (string) $request->input('strategy', 'distributed');
        $requests = min(max($request->integer('requests') ?: (int) config('inventory_locking.demo_burst_count', 10), 1), 50);
        $delayMs = max($request->integer('delay_ms') ?: (int) config('inventory_locking.demo_optimistic_delay_ms', 50), 0);

        $product = Product::query()->find($productId);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        $parallelSnapshot = $request->boolean('parallel_snapshot', false);
        $snapshotVersion = (int) $product->version;
        $outcomes = [];

        for ($i = 0; $i < $requests; $i++) {
            if ($strategy === 'distributed') {
                $result = $distributed->purchase($productId, 1);
            } elseif ($parallelSnapshot) {
                $result = $optimistic->purchaseWithReadVersion(
                    $productId,
                    1,
                    $snapshotVersion,
                    null,
                    $delayMs,
                );
            } else {
                $result = $optimistic->purchase($productId, 1, null, $delayMs);
            }

            $outcomes[] = [
                'attempt' => $i + 1,
                'status' => $result['status'],
            ];
        }

        $product->refresh();

        return response()->json([
            'message' => 'In-process concurrency stress completed.',
            'strategy' => $strategy,
            'parallel_snapshot' => $parallelSnapshot,
            'snapshot_version' => $parallelSnapshot ? $snapshotVersion : null,
            'requests' => $requests,
            'outcomes' => $outcomes,
            'final_stock' => $product->stock,
            'final_version' => $product->version,
            'metrics' => $metrics->snapshot(),
            'recent_attempts' => $metrics->recentAttempts(),
        ]);
    }
}
