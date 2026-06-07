<?php

namespace App\Services\LoadBalancing;

use Illuminate\Support\Facades\Cache;

/**
 * Lecture health checks: track which virtual backends are healthy.
 * Unhealthy servers receive 0% traffic (skipped by Round Robin).
 */
class BackendHealthRegistry
{
    /**
     * @return list<string> All configured backend IDs from config.
     */
    public function allBackendIds(): array
    {
        return array_map(
            fn (array $backend) => $backend['id'],
            config('load_balancing.backends', [])
        );
    }

    /**
     * @return list<string> Backend IDs currently marked healthy (rotation pool).
     */
    public function healthyBackendIds(): array
    {
        return array_values(array_filter(
            $this->allBackendIds(),
            fn (string $id) => $this->isHealthy($id)
        ));
    }

    public function isHealthy(string $serverId): bool
    {
        if (! in_array($serverId, $this->allBackendIds(), true)) {
            return false;
        }

        $key = $this->healthCacheKey($serverId);

        if (Cache::has($key)) {
            return (bool) Cache::get($key);
        }

        return true;
    }

    public function setHealthy(string $serverId, bool $healthy): void
    {
        if (! in_array($serverId, $this->allBackendIds(), true)) {
            throw new \InvalidArgumentException("Unknown backend: {$serverId}");
        }

        Cache::put($this->healthCacheKey($serverId), $healthy, 3600);
    }

    /**
     * @return array<string, bool> server id => healthy flag
     */
    public function healthSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->allBackendIds() as $id) {
            $snapshot[$id] = $this->isHealthy($id);
        }

        return $snapshot;
    }

    /** Clear demo overrides; all backends default to healthy. */
    public function resetHealthOverrides(): void
    {
        foreach ($this->allBackendIds() as $id) {
            Cache::forget($this->healthCacheKey($id));
        }
    }

    private function healthCacheKey(string $serverId): string
    {
        return config('load_balancing.cache_keys.health_prefix', 'load_balancer:health:').$serverId;
    }
}
