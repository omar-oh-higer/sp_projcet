@php
    $taskId = $id;
@endphp

{{-- Task 2: educational only --}}
@if(!empty($task['educational_only']))
    <div class="space-y-4">
        <div class="server-grid">
            <div class="server-card border-amber-300 bg-amber-50">
                <div class="font-semibold" x-text="t('Rate limit', 'تحديد معدل')"></div>
                <div class="text-2xl font-bold mt-1" x-text="rateLimitCount"></div>
                <div class="text-xs text-slate-500" x-text="t('429 responses (limit 3/min)', '429 (حد 3/دقيقة)')"></div>
            </div>
            <div class="server-card border-orange-300 bg-orange-50">
                <div class="font-semibold" x-text="t('Circuit breaker', 'قاطع دائرة')"></div>
                <div class="text-xs mt-2" x-text="t('503 on locked routes when open', '503 عند فتح القاطع')"></div>
            </div>
            <div class="server-card border-blue-300 bg-blue-50">
                <div class="font-semibold" x-text="t('Semaphore', 'سمافور')"></div>
                <div class="text-xs mt-2" x-text="t('Task 4 chunk concurrency cap', 'حد توازي دفعات المهمة 4')"></div>
            </div>
        </div>
        <button type="button" @click="runRateLimitDemo()"
            class="rounded-lg bg-amber-600 text-white px-4 py-2 text-sm font-medium hover:bg-amber-700"
            x-text="t('Fire 6 purchases (trigger rate limit)', 'أرسل 6 طلبات شراء (تحديد معدل)')">
        </button>
        <button type="button" @click="selectTask(4)"
            class="ms-2 rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100"
            x-text="t('Go to Task 4 (semaphore demo)', 'انتقل للمهمة 4')">
        </button>
    </div>

{{-- Task 9: read-only stress report --}}
@elseif(!empty($task['read_only']))
    <div class="space-y-4">
        <div class="rounded-lg bg-slate-100 p-4 font-mono text-sm">
            php artisan stress:concurrent --users=50 --scenario=safe
        </div>
        <button type="button" @click="loadStressReport()"
            class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm font-medium hover:bg-indigo-700"
            x-text="t('Load last report', 'تحميل آخر تقرير')">
        </button>
        <template x-if="stats.stress?.report">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs text-slate-500" x-text="t('Success', 'نجاح')"></div>
                    <div class="text-2xl font-bold text-emerald-600" x-text="stats.stress.report.success_requests ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs text-slate-500" x-text="t('Failed', 'فشل')"></div>
                    <div class="text-2xl font-bold text-red-600" x-text="stats.stress.report.failed_requests ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs text-slate-500" x-text="t('Avg response', 'متوسط الاستجابة')"></div>
                    <div class="text-2xl font-bold" x-text="(stats.stress.report.average_response_time_ms ?? 0) + ' ms'"></div>
                </div>
                <div class="rounded-lg border bg-white p-4">
                    <div class="text-xs text-slate-500" x-text="t('Integrity', 'سلامة')"></div>
                    <div class="text-lg font-bold" :class="stats.stress.report.data_integrity_pass ? 'text-emerald-600' : 'text-red-600'"
                        x-text="stats.stress.report.data_integrity_pass ? t('PASS', 'نجح') : t('FAIL', 'فشل')">
                    </div>
                </div>
            </div>
        </template>
        <template x-if="stats.stress && !stats.stress.report">
            <p class="text-sm text-slate-500" x-text="stats.stress.message || t('No report yet — run CLI first.', 'لا تقرير — شغّل CLI أولاً.')"></p>
        </template>
    </div>

{{-- AOP --}}
@elseif(!empty($task['performance_stats']))
    <div class="space-y-4">
        <button type="button" @click="loadPerformanceStats()"
            class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm font-medium hover:bg-indigo-700"
            x-text="t('Load performance stats', 'تحميل إحصائيات الأداء')">
        </button>
        <button type="button" @click="resetPerformance()"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-100"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <template x-if="stats.performance">
            <div class="space-y-3">
                <div class="grid grid-cols-3 gap-3 text-sm">
                    <div class="rounded-lg border bg-white p-3">
                        <div class="text-slate-500" x-text="t('Measurements', 'قياسات')"></div>
                        <div class="text-xl font-bold" x-text="stats.performance.summary?.total_measurements ?? 0"></div>
                    </div>
                    <div class="rounded-lg border bg-white p-3">
                        <div class="text-slate-500" x-text="t('Avg ms', 'متوسط ms')"></div>
                        <div class="text-xl font-bold" x-text="stats.performance.summary?.avg_duration_ms ?? 0"></div>
                    </div>
                    <div class="rounded-lg border bg-white p-3">
                        <div class="text-slate-500" x-text="t('Slow', 'بطيء')"></div>
                        <div class="text-xl font-bold text-amber-600" x-text="stats.performance.summary?.slow_count ?? 0"></div>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100">
                            <tr>
                                <th class="px-3 py-2 text-left" x-text="t('Route', 'مسار')"></th>
                                <th class="px-3 py-2 text-left" x-text="t('ms', 'ms')"></th>
                                <th class="px-3 py-2 text-left" x-text="t('Status', 'حالة')"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in (stats.performance.recent || []).slice(0, 15)" :key="row.name + row.recorded_at">
                                <tr class="border-t">
                                    <td class="px-3 py-1.5 font-mono text-xs" x-text="row.name"></td>
                                    <td class="px-3 py-1.5" x-text="row.duration_ms"></td>
                                    <td class="px-3 py-1.5" x-text="row.status_code"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

@else
    {{-- Standard before/after compare --}}
    <div class="grid md:grid-cols-2 gap-4">
        @include('demo.partials.result-card', ['side' => 'before', 'taskId' => $taskId])
        @include('demo.partials.result-card', ['side' => 'after', 'taskId' => $taskId])
    </div>

    {{-- Task 1: parallel demo --}}
    @if(!empty($task['parallel_demo']))
        <div class="mt-4 p-4 rounded-lg border border-red-200 bg-red-50/30">
            <h4 class="font-semibold text-sm mb-2" x-text="t('Concurrency stress (before path only)', 'ضغط تزامن (المسار قبل فقط)')"></h4>
            <button type="button" @click="runParallel(@json($taskId), 10)" :disabled="loading[@json($taskId) + '-parallel']"
                class="rounded-lg bg-red-600 text-white px-4 py-2 text-sm hover:bg-red-700 disabled:opacity-50"
                x-text="t('Run 10 parallel (unsafe)', '10 طلبات متوازية (غير آمن)')">
            </button>
            <template x-if="parallelResults">
                <p class="mt-2 text-sm">
                    <span x-text="t('Successes:', 'نجاح:')"></span> <strong x-text="parallelResults.successes"></strong>
                    · <span x-text="t('Conflicts:', 'تعارض:')"></span> <strong x-text="parallelResults.conflicts"></strong>
                    / <span x-text="parallelResults.total"></span>
                </p>
            </template>
        </div>
    @endif

    {{-- Task 3: timing bars --}}
    @if(!empty($task['timing_compare']))
        <template x-if="results[@json($taskId)].before.status && results[@json($taskId)].after.status">
            <div class="mt-4 p-4 rounded-lg border bg-white">
                <h4 class="font-semibold text-sm mb-3" x-text="t('Response time comparison', 'مقارنة زمن الاستجابة')"></h4>
                <div class="space-y-2">
                    <div>
                        <div class="text-xs mb-1" x-text="t('Before (inline invoice)', 'قبل (فاتورة مباشرة)')"></div>
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill metric-bar-fill-before" :style="'width:' + barWidth(results[@json($taskId)].before.responseTimeMs, Math.max(results[@json($taskId)].before.responseTimeMs, results[@json($taskId)].after.responseTimeMs, 1))"></div>
                        </div>
                        <span class="text-xs" x-text="Math.round(results[@json($taskId)].before.responseTimeMs) + ' ms'"></span>
                    </div>
                    <div>
                        <div class="text-xs mb-1" x-text="t('After (queued invoice)', 'بعد (فاتورة في طابور)')"></div>
                        <div class="metric-bar-track">
                            <div class="metric-bar-fill metric-bar-fill-after" :style="'width:' + barWidth(results[@json($taskId)].after.responseTimeMs, Math.max(results[@json($taskId)].before.responseTimeMs, results[@json($taskId)].after.responseTimeMs, 1))"></div>
                        </div>
                        <span class="text-xs" x-text="Math.round(results[@json($taskId)].after.responseTimeMs) + ' ms'"></span>
                    </div>
                </div>
            </div>
        </template>
    @endif

    {{-- Task 4: poll summary + full scenario --}}
    @if(!empty($task['poll_summary']))
        @include('demo.partials.tally-scenario', ['taskId' => $taskId])
    @endif

    {{-- Task 5: load distribution scenario --}}
    @if(!empty($task['load_scenario']))
        @include('demo.partials.load-scenario', ['taskId' => $taskId])
    @endif

    {{-- Task 6: cache scenario --}}
    @if(!empty($task['cache_scenario']))
        @include('demo.partials.cache-scenario', ['taskId' => $taskId])
    @endif

    {{-- Task 7: concurrency scenario --}}
    @if(!empty($task['concurrency_scenario']))
        @include('demo.partials.concurrency-scenario', ['taskId' => $taskId])
    @endif

    {{-- Task 8: simulate headers --}}
    @if(!empty($task['simulate_headers']))
        <div class="mt-4 flex flex-wrap items-center gap-4 p-4 rounded-lg border bg-slate-50 text-sm">
            <label class="flex items-center gap-2">
                <span x-text="t('Simulate fail at', 'محاكاة فشل')"></span>
                <select x-model="simulateFailAt" class="rounded border-slate-300">
                    <option value="after_payment">after_payment</option>
                    <option value="after_stock">after_stock</option>
                </select>
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" x-model="paymentDeclined">
                <span x-text="t('Payment declined', 'رفض الدفع')"></span>
            </label>
            <button type="button" @click="loadIntegrityStats()"
                class="rounded-lg border px-3 py-1.5 hover:bg-white"
                x-text="t('Integrity stats', 'إحصائيات السلامة')">
            </button>
        </div>
        <template x-if="stats.integrity?.metrics">
            <pre class="mt-2 text-xs p-3 bg-slate-100 rounded overflow-x-auto" x-text="JSON.stringify(stats.integrity.metrics, null, 2)"></pre>
        </template>
    @endif

    {{-- Task 10: benchmark --}}
    @if(!empty($task['benchmark_comparison']))
        <div class="mt-4">
            <button type="button" @click="loadBenchmarkComparison()"
                class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm hover:bg-indigo-700"
                x-text="t('Run slow + optimized + comparison', 'بطيء + محسّن + مقارنة')">
            </button>
            <template x-if="stats.benchmark?.comparison">
                <div class="mt-3 p-4 rounded-lg border bg-white text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-slate-500" x-text="t('Before avg ms', 'قبل')"></div>
                            <div class="text-xl font-bold text-red-600" x-text="stats.benchmark.comparison.before?.avg_response_time_ms ?? '—'"></div>
                        </div>
                        <div>
                            <div class="text-slate-500" x-text="t('After avg ms', 'بعد')"></div>
                            <div class="text-xl font-bold text-emerald-600" x-text="stats.benchmark.comparison.after?.avg_response_time_ms ?? '—'"></div>
                        </div>
                    </div>
                    <p class="mt-2 text-indigo-700" x-show="stats.benchmark.comparison.improvement"
                        x-text="(stats.benchmark.comparison.improvement?.response_time_percent_faster ?? 0) + '% ' + t('faster', 'أسرع')">
                    </p>
                </div>
            </template>
            <template x-if="results[@json($taskId)].before.status && results[@json($taskId)].after.status">
                <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                    <div class="p-3 rounded border">
                        <div x-text="t('DB queries (slow)', 'استعلامات بطيء')"></div>
                        <div class="text-2xl font-bold" x-text="results[@json($taskId)].before.body?.db_queries ?? '—'"></div>
                    </div>
                    <div class="p-3 rounded border">
                        <div x-text="t('DB queries (optimized)', 'استعلامات محسّن')"></div>
                        <div class="text-2xl font-bold text-emerald-600" x-text="results[@json($taskId)].after.body?.db_queries ?? '—'"></div>
                    </div>
                </div>
            </template>
        </div>
    @endif
@endif
