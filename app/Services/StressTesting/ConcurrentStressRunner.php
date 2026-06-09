<?php

namespace App\Services\StressTesting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Task 9: fires concurrent HTTP requests via Laravel Http::pool. */
class ConcurrentStressRunner
{
    /**
     * @return array{
     *     total_requests: int,
     *     success_requests: int,
     *     failed_requests: int,
     *     rejected_requests: int,
     *     connection_errors: int,
     *     average_response_time_ms: float|null,
     *     average_server_response_time_ms: float|null,
     *     system_crashed: bool,
     *     pool_duration_ms: float,
     *     request_results: list<array<string, mixed>>
     * }
     */
    public function run(
        string $baseUrl,
        string $path,
        int $productId,
        int $quantity,
        int $users,
        int $timeoutSeconds,
    ): array {
        $url = rtrim($baseUrl, '/').$path;
        $payload = [
            'product_id' => $productId,
            'quantity' => $quantity,
        ];

        $poolStarted = microtime(true);

        $responses = Http::pool(function ($pool) use ($url, $payload, $users, $timeoutSeconds) {
            $calls = [];

            for ($i = 0; $i < $users; $i++) {
                $calls[] = $pool->as((string) $i)
                    ->timeout($timeoutSeconds)
                    ->acceptJson()
                    ->post($url, $payload);
            }

            return $calls;
        });

        $poolDurationMs = (microtime(true) - $poolStarted) * 1000;

        $successRequests = 0;
        $failedRequests = 0;
        $rejectedRequests = 0;
        $connectionErrors = 0;
        $serverTimes = [];
        $requestResults = [];

        foreach ($responses as $key => $response) {
            if ($response instanceof ConnectionException) {
                $connectionErrors++;
                $requestResults[] = [
                    'key' => $key,
                    'connection_error' => true,
                    'status_code' => null,
                    'server_response_time_ms' => null,
                ];

                continue;
            }

            if (! $response instanceof Response) {
                $connectionErrors++;
                $requestResults[] = [
                    'key' => $key,
                    'connection_error' => true,
                    'status_code' => null,
                    'server_response_time_ms' => null,
                ];

                continue;
            }

            $status = $response->status();
            $serverTimeHeader = $response->header('X-Response-Time-Ms');
            $serverTimeMs = is_numeric($serverTimeHeader) ? (float) $serverTimeHeader : null;

            if ($serverTimeMs !== null) {
                $serverTimes[] = $serverTimeMs;
            }

            if ($response->successful()) {
                $successRequests++;
            } elseif ($status === 409) {
                $rejectedRequests++;
            } else {
                $failedRequests++;
            }

            $requestResults[] = [
                'key' => $key,
                'connection_error' => false,
                'status_code' => $status,
                'server_response_time_ms' => $serverTimeMs,
            ];
        }

        $threshold = (float) config('stress_testing.crash_connection_error_threshold', 0.10);
        $connectionErrorRate = $users > 0 ? $connectionErrors / $users : 0;
        $httpResponses = $users - $connectionErrors;

        $systemCrashed = $httpResponses === 0 || $connectionErrorRate > $threshold;

        $averageServerTime = count($serverTimes) > 0
            ? round(array_sum($serverTimes) / count($serverTimes), 3)
            : null;

        $averageClientTime = $httpResponses > 0
            ? round($poolDurationMs / $httpResponses, 3)
            : null;

        return [
            'total_requests' => $users,
            'success_requests' => $successRequests,
            'failed_requests' => $failedRequests + $connectionErrors,
            'rejected_requests' => $rejectedRequests,
            'connection_errors' => $connectionErrors,
            'average_response_time_ms' => $averageServerTime ?? $averageClientTime,
            'average_server_response_time_ms' => $averageServerTime,
            'average_pool_per_response_ms' => $averageClientTime,
            'system_crashed' => $systemCrashed,
            'pool_duration_ms' => round($poolDurationMs, 3),
            'request_results' => $requestResults,
        ];
    }
}
