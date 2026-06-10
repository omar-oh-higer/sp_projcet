<?php

namespace App\Services\LoadBalancing;

/**
 * Identity of the current Laravel process when running as a worker node.
 */
class NodeIdentity
{
    /**
     * @return array{id: string, port: int, handled_by: string}
     */
    public function current(): array
    {
        $id = (string) (config('load_balancing.node_id') ?: config('load_balancing.single_target', 'server-1'));
        $port = (int) config('load_balancing.node_port', 8000);

        return [
            'id' => $id,
            'port' => $port,
            'handled_by' => "node on port {$port}",
        ];
    }

    /**
     * Build worker JSON payload for a processed task.
     *
     * @return array{
     *     message: string,
     *     task_number: int,
     *     node_id: string,
     *     node_port: int,
     *     handled_by: string
     * }
     */
    public function processPayload(int $taskNumber): array
    {
        $node = $this->current();

        return [
            'message' => 'Task processed on this node',
            'task_number' => $taskNumber,
            'node_id' => $node['id'],
            'node_port' => $node['port'],
            'handled_by' => $node['handled_by'],
        ];
    }
}
