<?php

namespace App\Services\StressTesting;

use App\Models\Product;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Builds lecture-style stress demo view for /demo. */
class StressTestStatusBuilder
{
    public function build(StressTestMetrics $metrics, ?int $exampleProductId = null): array
    {
        $productId = $exampleProductId ?? 1;
        $recent = $metrics->recentRuns();
        $lastReport = $metrics->lastReport();
        $snapshot = $metrics->snapshot();
        $configuredBaseUrl = rtrim((string) config('stress_testing.default_base_url', 'http://127.0.0.1:8000'), '/');
        $effectiveBaseUrl = $this->effectiveBaseUrl($configuredBaseUrl);
        $baseUrlMismatch = strcasecmp($effectiveBaseUrl, $configuredBaseUrl) !== 0;
        $serverReachable = $this->probeServer($effectiveBaseUrl);
        $demoRunInProgress = $metrics->isDemoRunInProgress();
        $lastRun = $recent !== [] ? $recent[count($recent) - 1] : null;
        $connectionHints = $this->connectionFailureHints($lastRun, $baseUrlMismatch, $configuredBaseUrl, $effectiveBaseUrl);

        $unsafeRun = null;
        $safeRun = null;

        foreach (array_reverse($recent) as $row) {
            if ($row['scenario'] === 'unsafe' && $unsafeRun === null) {
                $unsafeRun = $row;
            }
            if ($row['scenario'] === 'safe' && $safeRun === null) {
                $safeRun = $row;
            }
        }

        $checker = app(StressTestIntegrityChecker::class);
        $dbAudit = $checker->snapshot($productId);
        $product = Product::query()->find($productId);
        $jsonPath = (string) config('stress_testing.report_json_path');

        return [
            'recent_runs' => array_map(fn (array $row) => [
                ...$row,
                'message_en' => $this->messageEn($row),
                'message_ar' => $this->messageAr($row),
            ], $recent),
            'last_report' => $this->normalizeReport($lastReport),
            'db_audit' => [
                'stock' => $dbAudit['stock'],
                'successful_orders' => $dbAudit['successful_orders'],
                'captured_payments' => $dbAudit['captured_payments'],
                'orphan_payments' => $dbAudit['orphan_payments'],
            ],
            'example_product_id' => $productId,
            'product_snapshot' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock,
                'price_cents' => $product->price_cents,
                'version' => $product->version,
            ] : null,
            'scenario_summary' => [
                'unsafe_success_total' => $unsafeRun['success_requests'] ?? null,
                'unsafe_failed_total' => $unsafeRun['failed_requests'] ?? null,
                'unsafe_rejected_total' => $unsafeRun['rejected_requests'] ?? null,
                'unsafe_integrity_pass' => $unsafeRun['data_integrity_pass'] ?? null,
                'unsafe_orphans_after' => $unsafeRun['orphan_payments_after'] ?? null,
                'unsafe_concurrent_users' => $unsafeRun['concurrent_users'] ?? null,
                'unsafe_message_en' => $unsafeRun !== null ? $this->messageEn($unsafeRun) : null,
                'unsafe_message_ar' => $unsafeRun !== null ? $this->messageAr($unsafeRun) : null,
                'safe_success_total' => $safeRun['success_requests'] ?? null,
                'safe_failed_total' => $safeRun['failed_requests'] ?? null,
                'safe_rejected_total' => $safeRun['rejected_requests'] ?? null,
                'safe_integrity_pass' => $safeRun['data_integrity_pass'] ?? null,
                'safe_orphans_after' => $safeRun['orphan_payments_after'] ?? null,
                'safe_concurrent_users' => $safeRun['concurrent_users'] ?? null,
                'safe_message_en' => $safeRun !== null ? $this->messageEn($safeRun) : null,
                'safe_message_ar' => $safeRun !== null ? $this->messageAr($safeRun) : null,
                'run_count' => count($recent),
                'runs_completed_total' => $snapshot['runs_completed'],
                'initial_demo_stock' => (int) config('stress_testing.demo_stock', 10),
                'final_stock' => $product?->stock,
                'last_concurrent_users' => $metrics->lastConcurrentUsers(),
            ],
            'report_file_exists' => File::exists($jsonPath),
            'report_json_path' => $jsonPath,
            'server_reachable' => $serverReachable,
            'base_url' => $effectiveBaseUrl,
            'configured_base_url' => $configuredBaseUrl,
            'effective_base_url' => $effectiveBaseUrl,
            'base_url_mismatch' => $baseUrlMismatch,
            'demo_run_in_progress' => $demoRunInProgress,
            'demo_run_lock' => $metrics->demoRunLockSnapshot(),
            'connection_failure_hint_en' => $connectionHints['en'],
            'connection_failure_hint_ar' => $connectionHints['ar'],
            'subprocess_hint_en' => $serverReachable
                ? ($baseUrlMismatch
                    ? 'Configured STRESS_TEST_BASE_URL ('.$configuredBaseUrl.') differs from this page ('.$effectiveBaseUrl.'). Web demo-run uses the current page origin.'
                    : null)
                : 'Stress target unreachable at '.$effectiveBaseUrl.' — ensure php artisan serve is running on that host/port.',
            'subprocess_hint_ar' => $serverReachable
                ? ($baseUrlMismatch
                    ? 'STRESS_TEST_BASE_URL ('.$configuredBaseUrl.') يختلف عن هذه الصفحة ('.$effectiveBaseUrl.'). demo-run يستخدم origin الحالي.'
                    : null)
                : 'هدف الضغط غير متاح عند '.$effectiveBaseUrl.' — شغّل serve على نفس المنفذ.',
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed>|null $report */
    private function normalizeReport(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        $scenarios = $report['scenarios'] ?? [];

        if (! is_array($scenarios) || $scenarios === []) {
            return $report;
        }

        $unsafe = null;
        $safe = null;

        foreach ($scenarios as $scenario) {
            if (($scenario['scenario'] ?? '') === 'unsafe') {
                $unsafe = $scenario;
            }
            if (($scenario['scenario'] ?? '') === 'safe') {
                $safe = $scenario;
            }
        }

        return [
            ...$report,
            'primary_scenario' => $scenarios[0] ?? null,
            'unsafe_scenario' => $unsafe,
            'safe_scenario' => $safe,
            'both_comparison' => ($unsafe !== null && $safe !== null) ? [
                'unsafe' => $unsafe,
                'safe' => $safe,
            ] : null,
        ];
    }

    private function effectiveBaseUrl(string $configuredBaseUrl): string
    {
        if (! app()->bound('request')) {
            return $configuredBaseUrl;
        }

        $request = request();

        if ($request->getHost() === '') {
            return $configuredBaseUrl;
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }

    /** @param  array<string, mixed>|null  $lastRun
     * @return array{en: string|null, ar: string|null}
     */
    private function connectionFailureHints(
        ?array $lastRun,
        bool $baseUrlMismatch,
        string $configuredBaseUrl,
        string $effectiveBaseUrl,
    ): array {
        if ($lastRun === null) {
            return ['en' => null, 'ar' => null];
        }

        $connectionErrors = (int) ($lastRun['connection_errors'] ?? 0);
        $total = (int) ($lastRun['total_requests'] ?? 0);
        $success = (int) ($lastRun['success_requests'] ?? 0);
        $systemCrashed = (bool) ($lastRun['system_crashed'] ?? false);

        if ($connectionErrors === 0 && ! $systemCrashed) {
            return ['en' => null, 'ar' => null];
        }

        if ($success === 0 && ($connectionErrors >= $total || $systemCrashed)) {
            $en = 'CONNECTION FAILURE — no checkout request succeeded. ';
            $ar = 'فشل اتصال — لم ينجح أي طلب checkout. ';

            if ($baseUrlMismatch) {
                $en .= 'Stress was configured for '.$configuredBaseUrl.' but you opened '.$effectiveBaseUrl.'. Use the main server (port 8000) or let demo-run auto-detect this page URL.';
                $ar .= 'الضغط كان موجهاً إلى '.$configuredBaseUrl.' بينما فتحت '.$effectiveBaseUrl.'. استخدم الخادم الرئيسي (8000) أو دع demo-run يكتشف URL الصفحة.';

                return ['en' => $en, 'ar' => $ar];
            }

            $en .= 'Ensure php artisan serve is running on '.$effectiveBaseUrl.'. Demo-run now runs in a background subprocess so the server can answer concurrent checkout requests.';
            $ar .= 'تأكد أن php artisan serve يعمل على '.$effectiveBaseUrl.'. demo-run يعمل الآن في subprocess بالخلفية.';

            return ['en' => $en, 'ar' => $ar];
        }

        return ['en' => null, 'ar' => null];
    }

    private function probeServer(string $baseUrl): bool
    {
        if ($this->isHandlingInboundHttpRequest()) {
            return true;
        }

        try {
            $response = Http::timeout(3)->get(rtrim($baseUrl, '/').'/demo');

            return $response->successful() || $response->status() === 404;
        } catch (Throwable) {
            return false;
        }
    }

    private function isHandlingInboundHttpRequest(): bool
    {
        if (! app()->bound('request')) {
            return false;
        }

        return request()->server('REQUEST_URI') !== null;
    }

    /** @param array<string, mixed> $row */
    private function messageEn(array $row): string
    {
        $scenario = (string) ($row['scenario'] ?? '');
        $success = (int) ($row['success_requests'] ?? 0);
        $failed = (int) ($row['failed_requests'] ?? 0);
        $connectionErrors = (int) ($row['connection_errors'] ?? 0);
        $systemCrashed = (bool) ($row['system_crashed'] ?? false);
        $integrity = ($row['data_integrity_pass'] ?? false) ? 'PASS' : 'FAIL';
        $orphans = $row['orphan_payments_after'] ?? 0;

        if ($connectionErrors > 0 || $systemCrashed) {
            $prefix = 'CONNECTION FAILURE — checkout never reached. ';

            if ($scenario === 'unsafe') {
                return $prefix."Unsafe: {$success} OK, {$failed} failed ({$connectionErrors} connection errors).";
            }

            if ($scenario === 'safe') {
                return $prefix."Safe ACID: {$success} OK, {$failed} failed ({$connectionErrors} connection errors).";
            }

            return $prefix."Stress ({$scenario}): {$success} success, {$failed} failed.";
        }

        if ($scenario === 'unsafe') {
            return "Unsafe non-atomic stress: {$success} OK, {$failed} failed — integrity {$integrity}, orphans={$orphans}.";
        }

        if ($scenario === 'safe') {
            return "Safe ACID stress: {$success} OK, {$failed} failed — integrity {$integrity}, orphans={$orphans}.";
        }

        return "Stress run ({$scenario}): {$success} success, {$failed} failed.";
    }

    /** @param array<string, mixed> $row */
    private function messageAr(array $row): string
    {
        $scenario = (string) ($row['scenario'] ?? '');
        $success = (int) ($row['success_requests'] ?? 0);
        $failed = (int) ($row['failed_requests'] ?? 0);
        $connectionErrors = (int) ($row['connection_errors'] ?? 0);
        $systemCrashed = (bool) ($row['system_crashed'] ?? false);
        $integrity = ($row['data_integrity_pass'] ?? false) ? 'نجح' : 'فشل';
        $orphans = $row['orphan_payments_after'] ?? 0;

        if ($connectionErrors > 0 || $systemCrashed) {
            $prefix = 'فشل اتصال — لم يصل checkout. ';

            if ($scenario === 'unsafe') {
                return $prefix."غير ذري: {$success} OK، {$failed} فشل ({$connectionErrors} أخطاء اتصال).";
            }

            if ($scenario === 'safe') {
                return $prefix."ACID: {$success} OK، {$failed} فشل ({$connectionErrors} أخطاء اتصال).";
            }

            return $prefix."ضغط ({$scenario}): {$success} نجاح، {$failed} فشل.";
        }

        if ($scenario === 'unsafe') {
            return "ضغط غير ذري: {$success} OK، {$failed} فشل — سلامة {$integrity}، يتامى={$orphans}.";
        }

        if ($scenario === 'safe') {
            return "ضغط ACID: {$success} OK، {$failed} فشل — سلامة {$integrity}، يتامى={$orphans}.";
        }

        return "تشغيل ضغط ({$scenario}): {$success} نجاح، {$failed} فشل.";
    }
}
