<?php

namespace App\Services\Benchmarking;

/** Task 10: stores last benchmark comparison for API readback. */
class BenchmarkMetrics
{
    /** @var array<string, mixed>|null */
    public ?array $lastComparison = null;

    public int $runsCompleted = 0;

    public function reset(): void
    {
        $this->lastComparison = null;
        $this->runsCompleted = 0;
    }

    /** @param array<string, mixed> $comparison */
    public function recordComparison(array $comparison): void
    {
        $this->lastComparison = $comparison;
        $this->runsCompleted++;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'runs_completed' => $this->runsCompleted,
            'last_comparison' => $this->lastComparison,
        ];
    }
}
