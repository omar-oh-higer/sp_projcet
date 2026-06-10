<?php

namespace App\Services\LoadBalancing;

use Illuminate\Support\Facades\Http;

/**
 * Forwards tasks to real worker nodes over HTTP (multi-port demo).
 */
class MultiServerProcessGateway
{
    public function __construct(
        private RoundRobinLoadBalancer $balancer,
        private SingleServerRouter $singleRouter,
        private BackendPool $backendPool,
        private LoadDistributionRecorder $recorder,
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
     * @return array<string, mixed>
     */
    private function forwardTo(string $targetId, string $distributionMode, int $taskNumber): array
    {
        $backend = $this->backendPool->find($targetId);
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
        $nodePort = (int) ($worker['node_port'] ?? $backend['port']);

        $this->recorder->record($targetId, $distributionMode, $taskNumber, $nodePort);

        return [
            'message' => $distributionMode === 'single'
                ? 'Task forwarded to single server (no load balancing).'
                : 'Task forwarded via Round Robin load balancer.',
            'distribution_mode' => $distributionMode,
            'scaling_model' => $distributionMode === 'single' ? 'vertical' : 'horizontal',
            'target_server' => $targetId,
            'target_port' => $nodePort,
            'worker_url' => $url,
            'worker_response' => $worker,
            'handled_by' => $worker['handled_by'] ?? "node on port {$nodePort}",
        ];
    }
}
