<?php

namespace App\Services\Benchmarking;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/** Task 10: benchmark comparison + run log persisted across HTTP requests. */
class BenchmarkMetrics
{
    private const MAX_RECENT = 30;

    public function reset(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /** @param array<string, mixed> $comparison */
    public function recordComparison(array $comparison): void
    {
        $this->mutate(static function (array &$state) use ($comparison): void {
            $state['runs_completed']++;
            $state['last_comparison'] = $comparison;
        });
    }

    /** @param array<string, mixed> $run */
    public function recordRun(array $run): void
    {
        $this->mutate(static function (array &$state) use ($run): void {
            $state['recent_runs'][] = [
                ...$run,
                'recorded_at' => now()->toIso8601String(),
            ];

            if (count($state['recent_runs']) > self::MAX_RECENT) {
                array_shift($state['recent_runs']);
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function lastComparison(): ?array
    {
        return $this->loadState()['last_comparison'];
    }

    /** @return list<array<string, mixed>> */
    public function recentRuns(): array
    {
        return $this->loadState()['recent_runs'];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $state = $this->loadState();

        return [
            'runs_completed' => $state['runs_completed'],
            'last_comparison' => $state['last_comparison'],
            'recent_runs' => $state['recent_runs'],
        ];
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
            'runs_completed' => 0,
            'last_comparison' => null,
            'recent_runs' => [],
        ];
    }

    private function cacheKey(): string
    {
        return (string) config('benchmarking.metrics_cache_key', 'benchmark:demo_metrics');
    }

    private function store(): CacheRepository
    {
        $storeName = config('benchmarking.metrics_store');

        return $storeName
            ? Cache::store((string) $storeName)
            : Cache::store();
    }
}
