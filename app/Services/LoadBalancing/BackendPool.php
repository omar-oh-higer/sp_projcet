<?php

namespace App\Services\LoadBalancing;

/**
 * Resolves configured backend IDs to URL/port metadata for HTTP forwarding.
 */
class BackendPool
{
    /**
     * @return array{id: string, url: string, port: int}
     */
    public function find(string $serverId): array
    {
        foreach ($this->all() as $backend) {
            if ($backend['id'] === $serverId) {
                return $backend;
            }
        }

        throw new \InvalidArgumentException("Unknown backend: {$serverId}");
    }

    /**
     * @return list<array{id: string, url: string, port: int}>
     */
    public function all(): array
    {
        return array_map(function (array $backend): array {
            return [
                'id' => (string) $backend['id'],
                'url' => rtrim((string) $backend['url'], '/'),
                'port' => (int) $backend['port'],
            ];
        }, config('load_balancing.backends', []));
    }

    public function processUrl(string $serverId): string
    {
        $backend = $this->find($serverId);
        $path = (string) config('load_balancing.process_path', '/api/load/process');

        return $backend['url'].$path;
    }
}
