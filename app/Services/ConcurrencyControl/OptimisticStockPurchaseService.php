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
            return [
                'status' => 'product_not_found',
                'stock' => null,
                'order_id' => null,
                'version' => null,
                'conflict' => false,
            ];
        }

        if ($product->stock < $quantity) {
            return [
                'status' => 'insufficient_stock',
                'stock' => $product->stock,
                'order_id' => null,
                'version' => $product->version,
                'conflict' => false,
            ];
        }

        $readVersion = (int) $product->version;

        if ($delayMs > 0) {
            usleep(min($delayMs, 5000) * 1000);
        }

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
                $this->metrics->optimisticConflicts++;
                $current = Product::query()->find($productId);

                return [
                    'status' => 'version_conflict',
                    'stock' => $current?->stock,
                    'order_id' => null,
                    'version' => $current?->version,
                    'conflict' => true,
                ];
            }

            $this->productCacheInvalidator->forget($productId);
            $this->metrics->optimisticSuccesses++;

            $product = Product::query()->find($productId);

            $order = Order::query()->create([
                'product_id' => $productId,
                'user_id' => $userId,
                'quantity' => $quantity,
                'status' => 'success',
            ]);

            return [
                'status' => 'success',
                'stock' => $product?->stock,
                'order_id' => $order->id,
                'version' => $product?->version,
                'conflict' => false,
            ];
        });
    }
}
