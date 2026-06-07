<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Services\ProductCatalog\ProductCacheInvalidator;
use Illuminate\Support\Facades\DB;

/** Task 1: concurrent-safe purchase using a DB transaction and row-level lock. */
class StockPurchaseService
{
    public function __construct(
        private ProductCacheInvalidator $productCacheInvalidator,
    ) {}

    /**
     * Safe stock decrement: one DB transaction, row lock (lockForUpdate), then decrement or record failed order.
     *
     * @return array{status: string, stock: int|null, order_id: int|null} status is success|product_not_found|insufficient_stock
     */
    public function purchase(int $productId, int $quantity, ?int $userId = null): array
    {
        return DB::transaction(function () use ($productId, $quantity, $userId) {
            $product = Product::query()
                ->whereKey($productId)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                return [
                    'status' => 'product_not_found',
                    'stock' => null,
                    'order_id' => null,
                ];
            }

            if ($product->stock < $quantity) {
                $order = Order::query()->create([
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'quantity' => $quantity,
                    'status' => 'failed',
                    'failure_reason' => 'insufficient_stock',
                ]);

                return [
                    'status' => 'insufficient_stock',
                    'stock' => $product->stock,
                    'order_id' => $order->id,
                ];
            }

            $product->stock = $product->stock - $quantity;
            $product->save();

            $this->productCacheInvalidator->forget($product->id);

            $order = Order::query()->create([
                'product_id' => $product->id,
                'user_id' => $userId,
                'quantity' => $quantity,
                'status' => 'success',
            ]);

            return [
                'status' => 'success',
                'stock' => $product->stock,
                'order_id' => $order->id,
            ];
        });
    }
}
