<?php

namespace App\Services\LoadBalancing;

use Illuminate\Support\Facades\Cache;

/**
 * After improvement: Round Robin across healthy backends (Session 5 lecture).
 *
 * Sequential rotation — server-1, server-2, server-3, then repeat.
 * Best when servers have similar capacity and requests take similar time.
 */
class RoundRobinLoadBalancer
{
    public function __construct(
        private BackendHealthRegistry $healthRegistry,
    ) {}

    /**
     * Pick the next healthy backend in rotation order.
     *
     * @throws \RuntimeException when no healthy backends remain
     */
    public function nextBackend(): string
    {
        $pool = $this->healthRegistry->healthyBackendIds();

        if ($pool === []) {
            throw new \RuntimeException('No healthy backends available for round robin.');
        }

        $key = config('load_balancing.cache_keys.round_robin_index', 'load_balancer:rr_index');
        $index = (int) Cache::increment($key);

        if ($index === 1) {
            Cache::put($key, 1, 3600);
        }

        $position = ($index - 1) % count($pool);

        return $pool[$position];
    }

    /** Reset rotation counter (useful between demo runs). */
    public function resetRotation(): void
    {
        Cache::forget(config('load_balancing.cache_keys.round_robin_index', 'load_balancer:rr_index'));
    }
}
