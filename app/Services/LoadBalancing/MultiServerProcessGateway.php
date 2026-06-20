<?php

namespace App\Services\LoadBalancing;

use Illuminate\Support\Facades\Http;

/**
 * Forwards tasks to real worker nodes over HTTP (multi-port demo).
 *
 * When the target is this same `php artisan serve` process, calls the worker
 * in-process — otherwise loopback HTTP deadlocks (single-threaded dev server).
 */
class MultiServerProcessGateway
{
    public function __construct(
        private RoundRobinLoadBalancer $balancer,
        private SingleServerRouter $singleRouter,
        private BackendPool $backendPool,
        private LoadDistributionRecorder $recorder,
        private NodeIdentity $nodeIdentity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forwardSingle(int $taskNumber): array
    {
        $targetId = $this->singleRouter->target();

        return $this->forwardTo($targetId, 'single', $taskNumber);
    }

    /**
     * @return array<string, mixed>
     */
    public function forwardBalanced(int $taskNumber): array
    {
        try {
            $targetId = $this->balancer->nextBackend();
        } catch (\RuntimeException $e) {
            return [
                'message' => $e->getMessage(),
                'error' => 'no_healthy_backends',
            ];
        }

        return $this->forwardTo($targetId, 'round_robin', $taskNumber);
    }

    /**
     * @param  array{id: string, url: string, port: int}  $backend
     * @return array<string, mixed>
     */
    private function forwardTo(string $targetId, string $distributionMode, int $taskNumber): array
    {
        $backend = $this->backendPool->find($targetId);

        if ($this->isCurrentNode($targetId, $backend)) {
            return $this->forwardInProcess($targetId, $distributionMode, $taskNumber, $backend, inProcess: true);
        }

        $url = $this->backendPool->processUrl($targetId);
        $timeout = (int) config('load_balancing.http_timeout', 5);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($url, ['task_number' => $taskNumber]);
        } catch (\Throwable $e) {
            return [
                'message' => 'Failed to reach worker node.',
                'error' => 'connection_failed',
                'target_server' => $targetId,
                'target_port' => $backend['port'],
                'worker_url' => $url,
                'detail' => $e->getMessage(),
                'hint_en' => $backend['port'] !== (int) config('load_balancing.node_port', 8000)
                    ? "Start node on port {$backend['port']} (see scripts/start-multi-server.ps1)."
                    : 'Start all three nodes or use the main scenario (route-single / route-balanced) on one server.',
                'hint_ar' => "شغّل node على المنفذ {$backend['port']} (start-multi-server.ps1).",
            ];
        }

        if (! $response->successful()) {
            return [
                'message' => 'Worker node returned an error.',
                'error' => 'worker_error',
                'target_server' => $targetId,
                'target_port' => $backend['port'],
                'worker_url' => $url,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ];
        }

        $worker = $response->json();

        return $this->forwardInProcess(
            $targetId,
            $distributionMode,
            $taskNumber,
            $backend,
            inProcess: false,
            worker: is_array($worker) ? $worker : [],
            workerUrl: $url,
        );
    }

    /**
     * @param  array{id: string, url: string, port: int}  $backend
     * @param  array<string, mixed>  $worker
     * @return array<string, mixed>
     */
    private function forwardInProcess(
        string $targetId,
        string $distributionMode,
        int $taskNumber,
        array $backend,
        bool $inProcess,
        array $worker = [],
        ?string $workerUrl = null,
    ): array {
        if ($inProcess) {
            $worker = $this->nodeIdentity->processPayload($taskNumber);
        }

        $targetPort = (int) $backend['port'];
        $url = $workerUrl ?? $this->backendPool->processUrl($targetId);

        $this->recorder->record($targetId, $distributionMode, $taskNumber, $targetPort);

        return [
            'message' => $distributionMode === 'single'
                ? 'Task forwarded to single server (no load balancing).'
                : 'Task forwarded via Round Robin load balancer.',
            'distribution_mode' => $distributionMode,
            'scaling_model' => $distributionMode === 'single' ? 'vertical' : 'horizontal',
            'target_server' => $targetId,
            'target_port' => $targetPort,
            'worker_url' => $url,
            'forwarded_in_process' => $inProcess,
            'worker_response' => $worker,
            'handled_by' => $worker['handled_by'] ?? "node on port {$targetPort}",
        ];
    }

    /**
     * @param  array{id: string, url: string, port: int}  $backend
     */
    private function isCurrentNode(string $targetId, array $backend): bool
    {
        $configuredId = config('load_balancing.node_id');

        if (is_string($configuredId) && $configuredId !== '' && $configuredId === $targetId) {
            return true;
        }

        return (int) config('load_balancing.node_port', 8000) === (int) $backend['port'];
    }
}
