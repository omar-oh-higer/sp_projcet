<?php

namespace App\Services\ConcurrencyControl;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/** Demo counters + attempt log persisted across HTTP requests (Task 7 stats API). */
class ConcurrencyControlMetrics
{
    private const MAX_RECENT = 30;

    public function incrementOptimisticConflicts(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['optimistic_conflicts']++;
        });
    }

    public function incrementOptimisticSuccesses(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['optimistic_successes']++;
        });
    }

    public function incrementLockAcquired(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['lock_acquired']++;
        });
    }

    public function incrementLockTimeouts(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['lock_timeouts']++;
        });
    }

    public function incrementDistributedSuccesses(): void
    {
        $this->mutate(static function (array &$state): void {
            $state['distributed_successes']++;
        });
    }

    public function reset(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /**
     * @param  'optimistic'|'distributed'  $strategy
     */
    public function recordAttempt(
        string $strategy,
        string $outcome,
        int $productId,
        ?int $stockAfter = null,
        ?int $version = null,
        ?int $httpStatus = null,
    ): void {
        $this->mutate(static function (array &$state) use ($strategy, $outcome, $productId, $stockAfter, $version, $httpStatus): void {
            $state['attempt_sequence']++;

            $state['recent_attempts'][] = [
                'attempt_index' => $state['attempt_sequence'],
                'strategy' => $strategy,
                'outcome' => $outcome,
                'http_status' => $httpStatus ?? self::httpStatusForOutcome($outcome),
                'product_id' => $productId,
                'stock_after' => $stockAfter,
                'version' => $version,
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($state['recent_attempts']) > self::MAX_RECENT) {
                array_shift($state['recent_attempts']);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function recentAttempts(): array
    {
        return $this->loadState()['recent_attempts'];
    }

    /** @return array<string, int> */
    public function snapshot(): array
    {
        $state = $this->loadState();

        return [
            'optimistic_conflicts' => $state['optimistic_conflicts'],
            'optimistic_successes' => $state['optimistic_successes'],
            'lock_acquired' => $state['lock_acquired'],
            'lock_timeouts' => $state['lock_timeouts'],
            'distributed_successes' => $state['distributed_successes'],
        ];
    }

    public static function httpStatusForOutcome(string $outcome): int
    {
        return match ($outcome) {
            'success' => 200,
            'version_conflict', 'insufficient_stock' => 409,
            'lock_timeout' => 503,
            'product_not_found' => 404,
            default => 500,
        };
    }

    /** @param  callable(array<string, mixed>&): void  $callback */
    private function mutate(callable $callback): void
    {
        $state = $this->loadState();
        $callback($state);
        $this->saveState($state);
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        /** @var array<string, mixed>|null $state */
        $state = $this->store()->get($this->cacheKey());

        if (! is_array($state)) {
            return $this->emptyState();
        }

        return array_merge($this->emptyState(), $state);
    }

    /** @param  array<string, mixed>  $state */
    private function saveState(array $state): void
    {
        $this->store()->forever($this->cacheKey(), $state);
    }

    /** @return array<string, mixed> */
    private function emptyState(): array
    {
        return [
            'optimistic_conflicts' => 0,
            'optimistic_successes' => 0,
            'lock_acquired' => 0,
            'lock_timeouts' => 0,
            'distributed_successes' => 0,
            'recent_attempts' => [],
            'attempt_sequence' => 0,
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('inventory_locking.metrics_cache_key', 'inventory:demo_metrics');
    }

    private function store(): CacheRepository
    {
        $storeName = config('inventory_locking.metrics_store');

        return $storeName
            ? Cache::store((string) $storeName)
            : Cache::store();
    }
}
