<?php

namespace App\Services\ConcurrencyControl;

use App\Models\Order;
use App\Models\Product;
use App\Services\ProductCatalog\ProductCacheInvalidator;
use Illuminate\Support\Facades\DB;

/** Task 7 “before”: optimistic locking via version column (no distributed lock). */
class OptimisticStockPurchaseService
{
    public function __construct(
        private ConcurrencyControlMetrics $metrics,
        private ProductCacheInvalidator $productCacheInvalidator,
    ) {}

    /**
     * @return array{status: string, stock: int|null, order_id: int|null, version: int|null, conflict: bool}
     */
    public function purchase(int $productId, int $quantity, ?int $userId = null, int $delayMs = 0): array
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            $this->recordOutcome($productId, 'product_not_found', null, null);

            return [
                'status' => 'product_not_found',
                'stock' => null,
                'order_id' => null,
                'version' => null,
                'conflict' => false,
            ];
        }

        if ($product->stock < $quantity) {
            $this->recordOutcome($productId, 'insufficient_stock', $product->stock, $product->version);

            return [
                'status' => 'insufficient_stock',
                'stock' => $product->stock,
                'order_id' => null,
                'version' => $product->version,
                'conflict' => false,
            ];
        }

        if ($delayMs > 0) {
            usleep(min($delayMs, 5000) * 1000);
        }

        return $this->commitPurchaseWithVersion(
            $productId,
            $quantity,
            (int) $product->version,
            $userId,
        );
    }

    /**
     * Demo helper: all attempts use the same read version (simulates parallel reads before any write).
     *
     * @return array{status: string, stock: int|null, order_id: int|null, version: int|null, conflict: bool}
     */
    public function purchaseWithReadVersion(
        int $productId,
        int $quantity,
        int $readVersion,
        ?int $userId = null,
        int $delayMs = 0,
    ): array {
        if (! Product::query()->whereKey($productId)->exists()) {
            $this->recordOutcome($productId, 'product_not_found', null, null);

            return [
                'status' => 'product_not_found',
                'stock' => null,
                'order_id' => null,
                'version' => null,
                'conflict' => false,
            ];
        }

        if ($delayMs > 0) {
            usleep(min($delayMs, 5000) * 1000);
        }

        return $this->commitPurchaseWithVersion($productId, $quantity, $readVersion, $userId);
    }

    /**
     * @return array{status: string, stock: int|null, order_id: int|null, version: int|null, conflict: bool}
     */
    private function commitPurchaseWithVersion(
        int $productId,
        int $quantity,
        int $readVersion,
        ?int $userId,
    ): array {
        return DB::transaction(function () use ($productId, $quantity, $userId, $readVersion) {
            $affected = Product::query()
                ->whereKey($productId)
                ->where('version', $readVersion)
                ->where('stock', '>=', $quantity)
                ->update([
                    'stock' => DB::raw('stock - '.(int) $quantity),
                    'version' => $readVersion + 1,
                ]);

            if ($affected === 0) {
                $this->metrics->incrementOptimisticConflicts();
                $current = Product::query()->find($productId);
                $this->recordOutcome(
                    $productId,
                    'version_conflict',
                    $current?->stock,
                    $current?->version,
                );

                return [
                    'status' => 'version_conflict',
                    'stock' => $current?->stock,
                    'order_id' => null,
                    'version' => $current?->version,
                    'conflict' => true,
                ];
            }

            $this->productCacheInvalidator->forget($productId);
            $this->metrics->incrementOptimisticSuccesses();

            $product = Product::query()->find($productId);

            $order = Order::query()->create([
                'product_id' => $productId,
                'user_id' => $userId,
                'quantity' => $quantity,
                'status' => 'success',
            ]);

            $this->recordOutcome($productId, 'success', $product?->stock, $product?->version);

            return [
                'status' => 'success',
                'stock' => $product?->stock,
                'order_id' => $order->id,
                'version' => $product?->version,
                'conflict' => false,
            ];
        });
    }

    private function recordOutcome(int $productId, string $outcome, ?int $stock, ?int $version): void
    {
        $this->metrics->recordAttempt(
            strategy: 'optimistic',
            outcome: $outcome,
            productId: $productId,
            stockAfter: $stock,
            version: $version,
        );
    }
}
