<?php

namespace App\Services\ConcurrencyControl;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Builds lecture-style concurrency demo view for /demo. */
class ConcurrencyControlStatusBuilder
{
    public function build(ConcurrencyControlMetrics $metrics, ?int $exampleProductId = null): array
    {
        $storeName = (string) config('inventory_locking.lock_store', 'redis');
        $productId = $exampleProductId ?? 1;
        $lockKey = app(InventoryDistributedLock::class)->lockKey($productId);
        $recent = $metrics->recentAttempts();
        $redisReachable = $this->probeLockStore($storeName);

        $optimisticConflicts = 0;
        $optimisticSuccesses = 0;
        $distributedSuccesses = 0;

        foreach ($recent as $row) {
            if ($row['strategy'] === 'optimistic') {
                if ($row['outcome'] === 'version_conflict') {
                    $optimisticConflicts++;
                } elseif ($row['outcome'] === 'success') {
                    $optimisticSuccesses++;
                }
            } elseif ($row['strategy'] === 'distributed' && $row['outcome'] === 'success') {
                $distributedSuccesses++;
            }
        }

        $product = Product::query()->find($productId);
        $snapshot = $metrics->snapshot();

        return [
            'recent_attempts' => array_map(fn (array $row) => [
                ...$row,
                'message_en' => $this->messageEn($row),
                'message_ar' => $this->messageAr($row),
            ], $recent),
            'lock_key_example' => $lockKey,
            'example_product_id' => $productId,
            'lock_store' => $storeName,
            'redis_reachable' => $redisReachable,
            'lock_ttl_seconds' => (int) config('inventory_locking.lock_ttl_seconds', 10),
            'lock_block_seconds' => (int) config('inventory_locking.lock_block_seconds', 5),
            'product_snapshot' => $product ? [
                'id' => $product->id,
                'stock' => $product->stock,
                'version' => $product->version,
            ] : null,
            'scenario_summary' => [
                'optimistic_conflicts_in_log' => $optimisticConflicts,
                'optimistic_successes_in_log' => $optimisticSuccesses,
                'distributed_successes_in_log' => $distributedSuccesses,
                'optimistic_conflicts_total' => $snapshot['optimistic_conflicts'],
                'optimistic_successes_total' => $snapshot['optimistic_successes'],
                'distributed_successes_total' => $snapshot['distributed_successes'],
                'attempt_count' => count($recent),
                'initial_demo_stock' => (int) config('inventory_locking.demo_stock', 10),
                'final_stock' => $product?->stock,
            ],
            'redis_hint_en' => $redisReachable
                ? null
                : 'Redis unreachable — set INVENTORY_LOCK_STORE=redis and start Redis. Distributed lock will timeout (503).',
            'redis_hint_ar' => $redisReachable
                ? null
                : 'Redis غير متاح — شغّل Redis و INVENTORY_LOCK_STORE=redis',
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function probeLockStore(string $storeName): bool
    {
        if ($storeName === 'array') {
            return true;
        }

        try {
            $store = Cache::store($storeName);
            $probeKey = 'inventory:demo_probe:'.uniqid('', true);
            $store->put($probeKey, 'ok', 5);
            $ok = $store->get($probeKey) === 'ok';
            $store->forget($probeKey);

            return $ok;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row */
    private function messageEn(array $row): string
    {
        $strategy = (string) ($row['strategy'] ?? '');
        $outcome = (string) ($row['outcome'] ?? '');
        $stock = $row['stock_after'] ?? '—';
        $version = $row['version'] ?? '—';

        if ($strategy === 'optimistic') {
            return match ($outcome) {
                'success' => "Optimistic purchase OK — stock={$stock}, version={$version}.",
                'version_conflict' => 'Version conflict (409) — another request updated inventory first.',
                'insufficient_stock' => "Insufficient stock (409) — stock={$stock}.",
                default => "Optimistic outcome: {$outcome}.",
            };
        }

        return match ($outcome) {
            'success' => "Distributed lock acquired + purchase OK — stock={$stock}.",
            'lock_timeout' => 'Lock timeout (503) — could not acquire Redis mutex in time.',
            'insufficient_stock' => "Lock acquired but insufficient stock (409) — stock={$stock}.",
            default => "Distributed outcome: {$outcome}.",
        };
    }

    /** @param array<string, mixed> $row */
    private function messageAr(array $row): string
    {
        $strategy = (string) ($row['strategy'] ?? '');
        $outcome = (string) ($row['outcome'] ?? '');

        if ($strategy === 'optimistic') {
            return match ($outcome) {
                'success' => 'شراء تفاؤلي ناجح.',
                'version_conflict' => 'تعارض إصدار (409) — عملية أخرى حدّثت المخزون.',
                'insufficient_stock' => 'مخزون غير كافٍ (409).',
                default => "نتيجة تفاؤلية: {$outcome}.",
            };
        }

        return match ($outcome) {
            'success' => 'قفل Redis + شراء ناجح.',
            'lock_timeout' => 'انتهت مهلة القفل (503).',
            'insufficient_stock' => 'قفل OK لكن مخزون غير كافٍ.',
            default => "نتيجة موزعة: {$outcome}.",
        };
    }
}
