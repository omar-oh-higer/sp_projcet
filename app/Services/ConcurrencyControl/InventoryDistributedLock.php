<?php

namespace App\Services\ConcurrencyControl;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/** Task 7: Redis-backed distributed lock (cluster-wide mutex before DB work). */
class InventoryDistributedLock
{
    public function __construct(
        private ConcurrencyControlMetrics $metrics,
    ) {}

    public function lockKey(int $productId): string
    {
        return config('inventory_locking.lock_prefix', 'inventory:product:').$productId;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{status: string, result: T|null}
     */
    public function executeWithLock(int $productId, callable $callback): array
    {
        $storeName = (string) config('inventory_locking.lock_store', 'redis');
        $ttl = (int) config('inventory_locking.lock_ttl_seconds', 10);
        $blockSeconds = (int) config('inventory_locking.lock_block_seconds', 5);

        try {
            $lock = Cache::store($storeName)->lock($this->lockKey($productId), $ttl);

            $result = $lock->block($blockSeconds, function () use ($callback) {
                $this->metrics->incrementLockAcquired();

                return $callback();
            });

            return [
                'status' => 'acquired',
                'result' => $result,
            ];
        } catch (LockTimeoutException) {
            $this->metrics->incrementLockTimeouts();

            return [
                'status' => 'timeout',
                'result' => null,
            ];
        }
    }
}
