{{-- Task 10: benchmarking full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-teal-200 bg-teal-50/40 p-5">
    <h4 class="font-bold text-teal-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-teal-800"
        x-text="t('Reset → 5× slow (N+1) → 5× optimized (eager load) → compare', 'Reset → 5× بطيء (N+1) → 5× محسّن (eager) → مقارنة')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runBenchmarkFullScenario({{ json_encode($taskId) }})"
            :disabled="benchmarkScenario.loading"
            class="rounded-lg bg-teal-600 text-white px-4 py-2 text-sm font-semibold hover:bg-teal-700 disabled:opacity-50">
            <span x-show="!benchmarkScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="benchmarkScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetBenchmarkDemo(true, false)"
            class="rounded-lg border border-teal-300 bg-white px-4 py-2 text-sm hover:bg-teal-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchBenchmarkStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-teal-700" x-show="benchmarkScenario.phase"
            x-text="benchmarkScenario.phase"></span>
    </div>

    {{-- Education: N+1 --}}
    <div class="rounded-lg border-2 border-teal-300 bg-white p-4 text-sm">
        <h5 class="font-semibold text-teal-900 mb-2" x-text="t('What are we measuring?', 'ماذا نقيس؟')"></h5>
        <p class="text-slate-700"
            x-text="t('Each sales report loads up to sample_order_limit success orders. The slow path runs a separate DB query per order (N+1). The optimized path uses eager loading (Order::with product).', 'كل تقرير يحمّل حتى sample_order_limit طلب ناجح. المسار البطيء يستعلم لكل طلب (N+1). المحسّن يستخدم eager loading.')">
        </p>
        <ul class="mt-2 list-disc ms-5 text-xs text-slate-600 space-y-1">
            <li x-text="t('Bottleneck span (slow): sequential_order_product_lookups', 'عنق زجاجة (بطيء): sequential_order_product_lookups')"></li>
            <li x-text="t('Optimized span: eager_load_orders_with_product', 'محسّن: eager_load_orders_with_product')"></li>
            <li x-text="t('X-Trace-Id + trace_spans show internal timing per step (Session 8).', 'X-Trace-Id + trace_spans توقيت داخلي لكل خطوة.')"></li>
        </ul>
    </div>

    {{-- Iteration selector --}}
    <div class="rounded-lg border bg-white p-4 text-sm">
        <h5 class="font-semibold mb-3" x-text="t('Iterations per mode', 'تكرارات لكل وضع')"></h5>
        <div class="flex flex-wrap gap-2 mb-3">
            <button type="button" @click="setBenchmarkIterations(3)"
                class="rounded-lg border px-3 py-1.5 text-xs"
                :class="benchmarkScenario.iterations === 3 ? 'border-teal-500 bg-teal-50 font-semibold' : 'border-slate-300'"
                x-text="t('3 (quick)', '3 (سريع)')"></button>
            <button type="button" @click="setBenchmarkIterations(5)"
                class="rounded-lg border px-3 py-1.5 text-xs"
                :class="benchmarkScenario.iterations === 5 ? 'border-teal-500 bg-teal-50 font-semibold' : 'border-slate-300'"
                x-text="t('5 (lecture)', '5 (محاضرة)')"></button>
            <button type="button" @click="setBenchmarkIterations(10)"
                class="rounded-lg border px-3 py-1.5 text-xs"
                :class="benchmarkScenario.iterations === 10 ? 'border-teal-500 bg-teal-50 font-semibold' : 'border-slate-300'"
                x-text="t('10 (thorough)', '10 (دقيق)')"></button>
            <label class="flex items-center gap-2 text-xs">
                <span x-text="t('Custom', 'مخصص')"></span>
                <input type="number" min="1" :max="benchmarkIterationsMax()"
                    x-model.number="benchmarkScenario.iterations"
                    @change="clampBenchmarkIterations()"
                    class="w-16 rounded border-slate-300 px-2 py-1 font-mono">
            </label>
        </div>
        <p class="text-xs text-slate-500"
            x-text="t('Demo runs slow then optimized this many times each, then averages.', 'يشغّل بطيء ثم محسّن بهذا العدد ثم يحسب المتوسط.')">
        </p>
    </div>

    {{-- Phase / error --}}
    <div class="rounded-lg border-2 border-teal-300 bg-teal-50 p-3 text-sm text-teal-900"
        x-show="benchmarkScenario.phase && benchmarkScenario.loading">
        <p class="font-bold" x-text="t('Benchmark running', 'القياس جاري')"></p>
        <p x-text="benchmarkScenario.phase"></p>
    </div>

    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3 text-sm text-red-900"
        x-show="benchmarkScenario.lastError">
        <p class="font-bold" x-text="t('Last run error', 'خطأ آخر تشغيل')"></p>
        <p x-text="benchmarkScenario.lastError"></p>
    </div>

    {{-- Seed hint --}}
    <div class="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        x-show="benchmarkScenario.stats && !benchmarkScenario.stats.ready_for_demo">
        <p class="font-bold" x-text="t('Demo data needed', 'بيانات العرض مطلوبة')"></p>
        <p x-text="lang === 'ar' ? benchmarkScenario.stats.seed_hint_ar : benchmarkScenario.stats.seed_hint_en"></p>
    </div>

    {{-- Empty state --}}
    <div class="rounded-lg border-2 border-slate-300 bg-slate-50 p-4 text-sm text-slate-700"
        x-show="benchmarkScenario.stats && !benchmarkHasComparison()">
        <p class="font-semibold" x-text="t('No benchmark comparison yet', 'لا توجد مقارنة بعد')"></p>
        <p class="mt-1"
            x-text="t('Click Run full scenario above. php artisan serve must be running.', 'اضغط تشغيل السيناريو الكامل. يجب أن يعمل php artisan serve.')">
        </p>
    </div>

    {{-- Product snapshot --}}
    <template x-if="benchmarkScenario.stats?.product_snapshot">
        <div class="rounded-lg border bg-white p-3 text-sm">
            <span x-text="t('Product', 'منتج')"></span> #<span x-text="benchmarkScenario.stats.product_snapshot.id"></span>
            · <span x-text="t('Success orders', 'طلبات ناجحة')"></span>:
            <strong x-text="benchmarkScenario.stats.order_count ?? '—'"></strong>
            · <span x-text="t('Sample limit', 'حد العينة')"></span>:
            <strong x-text="benchmarkScenario.stats.sample_order_limit ?? 20"></strong>
        </div>
    </template>

    {{-- Comparison summary --}}
    <template x-if="benchmarkHasComparison()">
        <div class="space-y-3">
            <h5 class="font-semibold text-sm" x-text="t('Comparison summary', 'ملخص المقارنة')"></h5>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Before avg ms', 'قبل (ms)')"></div>
                    <div class="text-xl font-bold text-red-600"
                        x-text="benchmarkScenario.stats.comparison?.before?.avg_response_time_ms ?? '—'"></div>
                    <div class="text-xs text-slate-500"
                        x-text="t('DB queries', 'استعلامات') + ': ' + (benchmarkScenario.stats.comparison?.before?.avg_db_queries ?? '—')"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('After avg ms', 'بعد (ms)')"></div>
                    <div class="text-xl font-bold text-emerald-600"
                        x-text="benchmarkScenario.stats.comparison?.after?.avg_response_time_ms ?? '—'"></div>
                    <div class="text-xs text-slate-500"
                        x-text="t('DB queries', 'استعلامات') + ': ' + (benchmarkScenario.stats.comparison?.after?.avg_db_queries ?? '—')"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('% faster', '% أسرع')"></div>
                    <div class="text-xl font-bold text-teal-700"
                        x-text="(benchmarkScenario.stats.comparison?.improvement?.response_time_percent_faster ?? '—') + '%'"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('% fewer queries', '% استعلامات أقل')"></div>
                    <div class="text-xl font-bold text-teal-700"
                        x-text="(benchmarkScenario.stats.comparison?.improvement?.db_queries_percent_fewer ?? '—') + '%'"></div>
                </div>
            </div>

            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-amber-700 mb-1" x-text="t('Bottleneck (slow)', 'عنق الزجاجة (بطيء)')"></div>
                <p class="font-mono text-xs"
                    x-text="benchmarkScenario.stats.comparison?.before?.bottleneck_span ?? benchmarkScenario.stats.last_slow_run?.bottleneck_span ?? '—'"></p>
                <p class="mt-2 text-xs text-slate-600"
                    x-show="benchmarkScenario.stats.comparison_message_en"
                    x-text="lang === 'ar' ? benchmarkScenario.stats.comparison_message_ar : benchmarkScenario.stats.comparison_message_en">
                </p>
            </div>
        </div>
    </template>

    {{-- Manual controls --}}
    <div class="flex flex-wrap items-center gap-3 p-4 rounded-lg border bg-white text-sm">
        <button type="button"
            @click="runBenchmarkOnce('slow')"
            :disabled="benchmarkScenario.loading"
            class="rounded-lg border border-red-300 px-3 py-1.5 hover:bg-red-50 text-xs"
            x-text="t('Run slow once', 'تشغيل بطيء')">
        </button>
        <button type="button"
            @click="runBenchmarkOnce('optimized')"
            :disabled="benchmarkScenario.loading"
            class="rounded-lg border border-emerald-300 px-3 py-1.5 hover:bg-emerald-50 text-xs"
            x-text="t('Run optimized once', 'تشغيل محسّن')">
        </button>
    </div>

    {{-- Trace run log --}}
    <template x-if="benchmarkScenario.stats?.db_traces?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Trace run log', 'سجل traces')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('Mode', 'وضع')"></th>
                            <th class="px-3 py-2" x-text="t('ms', 'ms')"></th>
                            <th class="px-3 py-2" x-text="t('Queries', 'استعلامات')"></th>
                            <th class="px-3 py-2" x-text="t('Bottleneck', 'عنق')"></th>
                            <th class="px-3 py-2" x-text="t('Trace', 'trace')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in benchmarkScenario.stats.db_traces" :key="row.trace_id + '-' + row.created_at">
                            <tr class="border-t" :class="{
                                'bg-red-50': row.mode === 'slow',
                                'bg-emerald-50': row.mode === 'optimized'
                            }">
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.mode === 'slow' ? 'bg-red-200' : 'bg-emerald-200'"
                                        x-text="row.mode"></span>
                                </td>
                                <td class="px-3 py-2 font-mono" x-text="row.total_duration_ms ?? '—'"></td>
                                <td class="px-3 py-2 font-mono" x-text="row.db_queries ?? '—'"></td>
                                <td class="px-3 py-2 text-xs font-mono" x-text="row.bottleneck_span ?? '—'"></td>
                                <td class="px-3 py-2 text-xs font-mono truncate max-w-[8rem]" x-text="row.trace_id ?? '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- CLI hint --}}
    <details class="rounded-lg border bg-white p-4">
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: CLI compare', 'اختياري: CLI')"></summary>
        <p class="text-xs text-slate-600 mt-2 font-mono">php artisan benchmark:compare --iterations=5</p>
        <p class="text-xs text-slate-600 mt-1 font-mono">php artisan db:seed --class=BenchmarkOrdersSeeder</p>
    </details>
</div>
