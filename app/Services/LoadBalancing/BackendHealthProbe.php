<?php

namespace App\Services\LoadBalancing;

use Illuminate\Support\Facades\Http;

/** Live HTTP health checks against worker node /up endpoints (Task 5 demo). */
class BackendHealthProbe
{
    public function __construct(
        private BackendPool $backendPool,
        private BackendHealthRegistry $healthRegistry,
        private NodeIdentity $nodeIdentity,
    ) {}

    /**
     * @return list<array{
     *     server_id: string,
     *     port: int,
     *     url: string,
     *     reachable: bool,
     *     probed_in_process: bool,
     *     latency_ms: float|null,
     *     error: string|null
     * }>
     */
    public function probeAll(): array
    {
        $timeout = (int) config('load_balancing.probe_timeout', 1);
        $results = [];

        foreach ($this->backendPool->all() as $backend) {
            if ($this->nodeIdentity->isCurrentBackend($backend)) {
                $results[] = [
                    'server_id' => $backend['id'],
                    'port' => $backend['port'],
                    'url' => $backend['url'],
                    'reachable' => true,
                    'probed_in_process' => true,
                    'latency_ms' => 0.0,
                    'error' => null,
                ];

                continue;
            }

            $url = $backend['url'].'/up';
            $started = microtime(true);
            $reachable = false;
            $error = null;

            try {
                $response = Http::timeout($timeout)->get($url);
                $reachable = $response->successful();
                if (! $reachable) {
                    $error = "HTTP {$response->status()}";
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $results[] = [
                'server_id' => $backend['id'],
                'port' => $backend['port'],
                'url' => $backend['url'],
                'reachable' => $reachable,
                'probed_in_process' => false,
                'latency_ms' => $reachable ? round((microtime(true) - $started) * 1000, 2) : null,
                'error' => $error,
            ];
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $probeResults
     * @return array<string, bool>
     */
    public function syncToRegistry(array $probeResults): array
    {
        $synced = [];

        foreach ($probeResults as $row) {
            $serverId = (string) ($row['server_id'] ?? '');
            if ($serverId === '') {
                continue;
            }

            $healthy = (bool) ($row['reachable'] ?? false);
            $this->healthRegistry->setHealthy($serverId, $healthy);
            $synced[$serverId] = $healthy;
        }

        return $synced;
    }

    /**
     * @param  list<array<string, mixed>>  $probeResults
     * @return 'live'|'simulated'|'degraded'
     */
    public function scenarioModeHint(array $probeResults): string
    {
        $reachable = array_values(array_filter(
            $probeResults,
            fn (array $row) => (bool) ($row['reachable'] ?? false),
        ));

        $reachableCount = count($reachable);
        $total = count($probeResults);

        if ($reachableCount === 0) {
            return 'simulated';
        }

        if ($reachableCount === $total) {
            return 'live';
        }

        $remoteReachable = array_values(array_filter(
            $reachable,
            fn (array $row) => ! ($row['probed_in_process'] ?? false),
        ));

        if ($remoteReachable === []) {
            return 'simulated';
        }

        return 'degraded';
    }

    /**
     * @param  list<array<string, mixed>>  $probeResults
     * @return array<string, bool>
     */
    public function reachableMap(array $probeResults): array
    {
        $map = [];

        foreach ($probeResults as $row) {
            $map[(string) $row['server_id']] = (bool) ($row['reachable'] ?? false);
        }

        return $map;
    }
}
