<?php

namespace App\Services\Benchmarking;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Task 10: lightweight span tracing for bottleneck detection (Session 8). */
class RequestSpanTracer
{
    public readonly string $traceId;

    /** @var list<array{name: string, duration_ms: float}> */
    private array $spans = [];

    public function __construct()
    {
        $this->traceId = (string) Str::uuid();
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function trace(string $name, callable $callback)
    {
        $started = microtime(true);
        $result = $callback();
        $durationMs = round((microtime(true) - $started) * 1000, 3);

        $this->spans[] = [
            'name' => $name,
            'duration_ms' => $durationMs,
        ];

        $threshold = (int) config('benchmarking.bottleneck_log_threshold_ms', 100);
        if ($durationMs >= $threshold) {
            Log::warning('Benchmark bottleneck span exceeded threshold', [
                'trace_id' => $this->traceId,
                'span' => $name,
                'duration_ms' => $durationMs,
                'threshold_ms' => $threshold,
            ]);
        }

        return $result;
    }

    /** @return list<array{name: string, duration_ms: float}> */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @return array{name: string, duration_ms: float}|null */
    public function bottleneckSpan(): ?array
    {
        if ($this->spans === []) {
            return null;
        }

        return array_reduce(
            $this->spans,
            fn (?array $carry, array $span) => $carry === null || $span['duration_ms'] > $carry['duration_ms']
                ? $span
                : $carry,
        );
    }

    public function bottleneckAnalysis(float $totalDurationMs): ?string
    {
        $bottleneck = $this->bottleneckSpan();

        if ($bottleneck === null || $totalDurationMs <= 0) {
            return null;
        }

        $share = round(($bottleneck['duration_ms'] / $totalDurationMs) * 100, 1);

        return sprintf(
            '%s consumed %s%% of total time — inspect trace_spans for sequential DB round-trips.',
            $bottleneck['name'],
            $share,
        );
    }
}
