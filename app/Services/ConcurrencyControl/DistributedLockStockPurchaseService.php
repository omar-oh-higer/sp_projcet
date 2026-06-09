<?php

namespace App\Services\ConcurrencyControl;

use App\Services\StockPurchaseService;

/** Task 7 “after”: distributed Redis lock then pessimistic DB purchase inside lock. */
class DistributedLockStockPurchaseService
{
    public function __construct(
        private InventoryDistributedLock $distributedLock,
        private StockPurchaseService $stockPurchaseService,
        private ConcurrencyControlMetrics $metrics,
    ) {}

    /**
     * @return array{status: string, stock: int|null, order_id: int|null, lock_acquired: bool, purchase: array<string, mixed>|null}
     */
    public function purchase(int $productId, int $quantity, ?int $userId = null): array
    {
        $lockOutcome = $this->distributedLock->executeWithLock(
            $productId,
            fn () => $this->stockPurchaseService->purchase($productId, $quantity, $userId),
        );

        if ($lockOutcome['status'] === 'timeout') {
            return [
                'status' => 'lock_timeout',
                'stock' => null,
                'order_id' => null,
                'lock_acquired' => false,
                'purchase' => null,
            ];
        }

        /** @var array{status: string, stock: int|null, order_id: int|null} $purchase */
        $purchase = $lockOutcome['result'];

        if ($purchase['status'] === 'success') {
            $this->metrics->distributedSuccesses++;
        }

        return [
            'status' => $purchase['status'],
            'stock' => $purchase['stock'],
            'order_id' => $purchase['order_id'],
            'lock_acquired' => true,
            'purchase' => $purchase,
        ];
    }
}
