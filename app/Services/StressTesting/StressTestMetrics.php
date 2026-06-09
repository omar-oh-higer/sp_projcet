<?php

namespace App\Services\StressTesting;

/** Task 9: stores last stress report for API readback. */
class StressTestMetrics
{
    /** @var array<string, mixed>|null */
    public ?array $lastReport = null;

    /** @var list<array<string, mixed>> */
    public array $scenarioReports = [];

    public int $runsCompleted = 0;

    public function reset(): void
    {
        $this->lastReport = null;
        $this->scenarioReports = [];
        $this->runsCompleted = 0;
    }

    /** @param array<string, mixed> $report */
    public function record(array $report): void
    {
        $this->lastReport = $report;
        $this->scenarioReports[] = $report;
        $this->runsCompleted++;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'runs_completed' => $this->runsCompleted,
            'last_report' => $this->lastReport,
            'scenario_reports' => $this->scenarioReports,
        ];
    }
}
