<?php

namespace App\Services\StressTesting;

use App\Models\Product;

/** Runs concurrent checkout stress scenarios (shared by CLI and demo API). */
class StressTestOrchestrator
{
    public function __construct(
        private ConcurrentStressRunner $concurrentStressRunner,
        private StressTestIntegrityChecker $stressTestIntegrityChecker,
        private StressTestReportBuilder $stressTestReportBuilder,
    ) {}

    /**
     * @return array{reports: list<array<string, mixed>>, combined: array<string, mixed>|null}
     */
    public function runScenarios(
        int $productId,
        int $quantity,
        int $users,
        string $baseUrl,
        string $scenarioKey,
        string $writeOutput = 'none',
    ): array {
        $scenarios = StressTestScenario::forKey($scenarioKey);
        $timeout = (int) config('stress_testing.request_timeout_seconds', 30);
        $reports = [];

        foreach ($scenarios as $scenario) {
            $before = $this->stressTestIntegrityChecker->snapshot($productId);

            $useProcessPool = $scenario->key === 'unsafe'
                && filter_var(config('stress_testing.unsafe_use_process_pool', true), FILTER_VALIDATE_BOOL);

            $runMetrics = $useProcessPool
                ? $this->concurrentStressRunner->runViaProcessWorkers(
                    scenario: $scenario,
                    productId: $productId,
                    quantity: $quantity,
                    users: $users,
                )
                : $this->concurrentStressRunner->run(
                    baseUrl: $baseUrl,
                    path: $scenario->path,
                    productId: $productId,
                    quantity: $quantity,
                    users: $users,
                    timeoutSeconds: $timeout,
                );

            $after = $this->stressTestIntegrityChecker->snapshot($productId);

            $integrity = $this->stressTestIntegrityChecker->evaluate(
                before: $before,
                after: $after,
                successRequests: $runMetrics['success_requests'],
                quantity: $quantity,
                scenario: $scenario,
            );

            $reports[] = $this->stressTestReportBuilder->build(
                scenario: $scenario,
                productId: $productId,
                quantity: $quantity,
                users: $users,
                baseUrl: $baseUrl,
                runMetrics: $runMetrics,
                integrity: $integrity,
            );
        }

        $combined = null;

        if ($reports !== []) {
            if (in_array($writeOutput, ['json', 'md', 'both'], true)) {
                $this->stressTestReportBuilder->writeCombinedReport($reports, $writeOutput);
                $combined = $this->stressTestReportBuilder->lastCombinedReport();
            } else {
                $combined = [
                    'task' => 'Task 9 — Concurrent Stress Test',
                    'executed_at' => now()->toIso8601String(),
                    'scenarios' => $reports,
                ];
                app(StressTestMetrics::class)->recordCombinedReport($combined);
            }
        }

        return [
            'reports' => $reports,
            'combined' => $combined,
        ];
    }

    public function assertProductExists(int $productId): Product
    {
        $product = Product::query()->find($productId);

        if (! $product) {
            throw new \InvalidArgumentException('Product not found for id '.$productId);
        }

        return $product;
    }
}
