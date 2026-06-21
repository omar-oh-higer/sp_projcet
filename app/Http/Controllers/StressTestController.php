<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\StressTesting\StressDemoProcessLauncher;
use App\Services\StressTesting\StressTestMetrics;
use App\Services\StressTesting\StressTestOrchestrator;
use App\Services\StressTesting\StressTestStatusBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Throwable;

/** Task 9: concurrent stress test reports + demo scenario APIs. */
class StressTestController extends Controller
{
    public function stats(
        Request $request,
        StressTestMetrics $stressTestMetrics,
        StressTestStatusBuilder $stressTestStatusBuilder,
    ): JsonResponse {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $view = $stressTestStatusBuilder->build($stressTestMetrics, $productId);

        return response()->json([
            'message' => 'Stress test demo stats',
            'demo_users' => (int) config('stress_testing.demo_users', 100),
            'demo_users_max' => (int) config('stress_testing.demo_users_max', 100),
            'demo_stock' => (int) config('stress_testing.demo_stock', 10),
            'demo_request_delay_ms' => (int) config('stress_testing.demo_request_delay_ms', 600),
            'last_concurrent_users' => $stressTestMetrics->lastConcurrentUsers(),
            'metrics' => $stressTestMetrics->snapshot(),
            ...$view,
        ]);
    }

    public function lastReport(StressTestMetrics $stressTestMetrics): JsonResponse
    {
        $cached = $stressTestMetrics->lastReport();

        if ($cached !== null) {
            return response()->json([
                'message' => 'Last stress test report (from cache)',
                'report' => $cached,
            ]);
        }

        $jsonPath = (string) config('stress_testing.report_json_path');

        if (File::exists($jsonPath)) {
            $report = json_decode(File::get($jsonPath), true);

            return response()->json([
                'message' => 'Last stress test report (from file)',
                'report' => $report,
            ]);
        }

        return response()->json([
            'message' => 'No stress test report yet. Run: php artisan stress:concurrent --users=100 --scenario=safe',
            'report' => null,
        ]);
    }

    public function reset(StressTestMetrics $stressTestMetrics): JsonResponse
    {
        $stressTestMetrics->reset();

        return response()->json([
            'message' => 'Stress test metrics reset',
            'metrics' => $stressTestMetrics->snapshot(),
        ]);
    }

    public function demoReset(Request $request, StressTestMetrics $stressTestMetrics): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $demoStock = (int) config('stress_testing.demo_stock', 10);
        $resetMetrics = $request->boolean('reset_metrics', true);
        $clearReport = $request->boolean('clear_report', false);

        $product = Product::query()->find($productId);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found',
            ], 404);
        }

        Payment::query()
            ->where('product_id', $productId)
            ->where('status', 'captured')
            ->whereNull('order_id')
            ->delete();

        Order::query()
            ->where('product_id', $productId)
            ->whereNull('payment_id')
            ->delete();

        $product->update([
            'stock' => $demoStock,
            'version' => 0,
        ]);

        if ($resetMetrics) {
            $stressTestMetrics->reset();
        }

        if ($clearReport) {
            $jsonPath = (string) config('stress_testing.report_json_path');
            if (File::exists($jsonPath)) {
                File::delete($jsonPath);
            }
        }

        $checker = app(\App\Services\StressTesting\StressTestIntegrityChecker::class);

        return response()->json([
            'message' => $resetMetrics
                ? 'Demo stock restored, orphans cleaned, stress metrics reset.'
                : 'Demo stock restored and orphans cleaned (metrics kept for scenario log).',
            'product_id' => $productId,
            'stock' => $demoStock,
            'orphan_payments' => $checker->snapshot($productId)['orphan_payments'],
            'metrics_reset' => $resetMetrics,
            'report_cleared' => $clearReport,
        ]);
    }

    public function demoRun(
        Request $request,
        StressTestOrchestrator $orchestrator,
        StressDemoProcessLauncher $stressDemoProcessLauncher,
    ): JsonResponse
    {
        $productId = max($request->integer('product_id') ?: 1, 1);
        $quantity = max($request->integer('quantity') ?: (int) config('stress_testing.default_quantity', 1), 1);
        $scenario = (string) ($request->input('scenario') ?: 'unsafe');

        if (! in_array($scenario, ['safe', 'unsafe', 'both'], true)) {
            return response()->json([
                'message' => 'Invalid scenario. Use safe, unsafe, or both.',
            ], 422);
        }

        try {
            $orchestrator->assertProductExists($productId);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        }

        $metrics = app(StressTestMetrics::class);

        if ($metrics->isDemoRunInProgress()) {
            return response()->json([
                'message' => 'A stress demo run is already in progress. Wait for it to finish or refresh stats.',
                'status' => 'running',
                'demo_run_in_progress' => true,
                'lock' => $metrics->demoRunLockSnapshot(),
            ], 409);
        }

        $demoUsers = (int) config('stress_testing.demo_users', 100);
        $maxUsers = (int) config('stress_testing.demo_users_max', 100);
        $users = min(max($request->integer('users') ?: $demoUsers, 1), $maxUsers);
        $writeReport = $request->boolean('write_report', true);
        $writeOutput = $writeReport ? 'json' : 'none';
        $baseUrl = $this->resolveStressBaseUrl($request);

        $runsBefore = count($metrics->recentRuns());

        try {
            $stressDemoProcessLauncher->start([
                '--users='.$users,
                '--product='.$productId,
                '--quantity='.$quantity,
                '--baseUrl='.$baseUrl,
                '--scenario='.$scenario,
                '--output='.$writeOutput,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Failed to start stress demo subprocess.',
                'exit_code' => 1,
                'output' => $exception->getMessage(),
                'scenario' => $scenario,
                'concurrent_users' => $users,
                'report' => null,
            ], 500);
        }

        $metrics->markDemoRunStarted([
            'scenario' => $scenario,
            'concurrent_users' => $users,
            'base_url' => $baseUrl,
            'product_id' => $productId,
            'runs_before' => $runsBefore,
        ]);

        return response()->json([
            'message' => 'Stress demo run started in background.',
            'status' => 'running',
            'exit_code' => null,
            'output' => null,
            'scenario' => $scenario,
            'concurrent_users' => $users,
            'base_url' => $baseUrl,
            'demo_run_in_progress' => true,
            'report' => null,
        ], 202);
    }

    private function resolveStressBaseUrl(Request $request): string
    {
        $fromRequest = $request->input('base_url');

        if (is_string($fromRequest) && $fromRequest !== '') {
            return rtrim($fromRequest, '/');
        }

        if ($request->getHost() !== '') {
            return rtrim($request->getSchemeAndHttpHost(), '/');
        }

        return rtrim((string) config('stress_testing.default_base_url'), '/');
    }
}
