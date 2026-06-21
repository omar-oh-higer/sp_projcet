<?php

namespace App\Services\PerformanceMonitoring;

use App\Models\PerformanceMeasurement;

/** Builds lecture-style AOP performance demo view for /demo. */
class PerformanceStatusBuilder
{
    public function build(PerformanceMonitor $performanceMonitor): array
    {
        $base = $performanceMonitor->stats();
        $slowThreshold = (float) ($base['slow_threshold_ms'] ?? 500);
        $recent = $base['recent'] ?? [];
        $total = (int) ($base['summary']['total_measurements'] ?? 0);

        $topRoutes = PerformanceMeasurement::query()
            ->where('channel', 'http')
            ->selectRaw('name, COUNT(*) as count, AVG(duration_ms) as avg_duration_ms, MAX(duration_ms) as max_duration_ms')
            ->groupBy('name')
            ->orderByDesc('avg_duration_ms')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->count,
                'avg_duration_ms' => round((float) $row->avg_duration_ms, 3),
                'max_duration_ms' => round((float) $row->max_duration_ms, 3),
                'is_slow' => (float) $row->avg_duration_ms >= $slowThreshold,
            ])
            ->values()
            ->all();

        $slowestRecent = array_values(array_filter(
            $recent,
            fn (array $row) => (float) ($row['duration_ms'] ?? 0) >= $slowThreshold,
        ));

        return [
            ...$base,
            'has_measurements' => $total > 0,
            'top_routes' => $topRoutes,
            'slowest_recent' => array_slice($slowestRecent, 0, 10),
            'demo_request_delay_ms' => (int) config('performance_monitoring.demo_request_delay_ms', 200),
            'demo_probe_endpoints' => config('performance_monitoring.demo_probe_endpoints', []),
            'aspect_message_en' => $this->messageEn($base),
            'aspect_message_ar' => $this->messageAr($base),
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $stats */
    private function messageEn(array $stats): string
    {
        $total = (int) ($stats['summary']['total_measurements'] ?? 0);
        $slow = (int) ($stats['summary']['slow_count'] ?? 0);
        $threshold = (float) ($stats['slow_threshold_ms'] ?? 500);

        if ($total === 0) {
            return 'Around-advice middleware (MeasureRequestPerformance) wraps every API route — controllers stay clean. Run the full scenario to record sample traffic, then inspect aggregates here and X-Response-Time-Ms on each response.';
        }

        return sprintf(
            'AOP around-advice recorded %d HTTP/job measurements. %d exceeded slow threshold (%.0f ms). Check top_routes for hotspots — Task 10 slow benchmark often appears slowest. Every probed API returns X-Response-Time-Ms without controller code.',
            $total,
            $slow,
            $threshold,
        );
    }

    /** @param array<string, mixed> $stats */
    private function messageAr(array $stats): string
    {
        $total = (int) ($stats['summary']['total_measurements'] ?? 0);
        $slow = (int) ($stats['summary']['slow_count'] ?? 0);
        $threshold = (float) ($stats['slow_threshold_ms'] ?? 500);

        if ($total === 0) {
            return 'Middleware around-advice (MeasureRequestPerformance) يلف كل مسار API — الـ controllers نظيفة. شغّل السيناريو الكامل لتسجيل حركة تجريبية ثم راجع المجاميع هنا و X-Response-Time-Ms على كل استجابة.';
        }

        return sprintf(
            'سجّل AOP around-advice %d قياساً (HTTP/job). %d تجاوزت حد البطء (%.0f ms). راجع top_routes — benchmark البطيء (Task 10) غالباً الأبطأ. كل API يُرجع X-Response-Time-Ms بدون كود في الـ controller.',
            $total,
            $slow,
            $threshold,
        );
    }
}
