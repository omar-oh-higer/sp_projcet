{{-- AOP: performance monitoring full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-violet-200 bg-violet-50/40 p-5">
    <h4 class="font-bold text-violet-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-violet-800"
        x-text="t('Reset → curated API probes → compare aggregated stats (no self-recording on /api/performance/*)', 'Reset → مسارات تجريبية → مقارنة إحصائيات (بدون تسجيل /api/performance/*)')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runPerformanceFullScenario({{ json_encode($taskId) }})"
            :disabled="performanceScenario.loading"
            class="rounded-lg bg-violet-600 text-white px-4 py-2 text-sm font-semibold hover:bg-violet-700 disabled:opacity-50">
            <span x-show="!performanceScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="performanceScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetPerformanceDemo()"
            class="rounded-lg border border-violet-300 bg-white px-4 py-2 text-sm hover:bg-violet-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchPerformanceStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-violet-700" x-show="performanceScenario.phase"
            x-text="performanceScenario.phase"></span>
    </div>

    {{-- Education: AOP terms --}}
    <div class="rounded-lg border-2 border-violet-300 bg-white p-4 text-sm">
        <h5 class="font-semibold text-violet-900 mb-2" x-text="t('What is AOP here?', 'ما هو AOP هنا؟')"></h5>
        <p class="text-slate-700"
            x-text="t('MeasureRequestPerformance middleware wraps every API route (around advice). Controllers stay clean — timing is recorded at the join point before/after the handler runs.', 'Middleware MeasureRequestPerformance يلف كل مسار API (around advice). الـ controllers نظيفة — التوقيت يُسجّل عند join point قبل/بعد المعالج.')">
        </p>
        <ul class="mt-2 list-disc ms-5 text-xs text-slate-600 space-y-1">
            <li x-text="t('Pointcut: all api/* routes in bootstrap/app.php', 'Pointcut: كل api/* في bootstrap/app.php')"></li>
            <li x-text="t('Aspect: PerformanceMonitor persists channel, route name, duration_ms', 'Aspect: PerformanceMonitor يحفظ channel واسم المسار و duration_ms')"></li>
            <li x-text="t('X-Response-Time-Ms header on every response (even excluded stats routes)', 'رأس X-Response-Time-Ms على كل استجابة')"></li>
            <li x-text="t('Job channel: ProcessDailySalesTallyJob via job middleware', 'قناة job: ProcessDailySalesTallyJob عبر middleware المهمة')"></li>
        </ul>
    </div>

    {{-- Phase / error --}}
    <div class="rounded-lg border-2 border-violet-300 bg-violet-50 p-3 text-sm text-violet-900"
        x-show="performanceScenario.phase && performanceScenario.loading">
        <p class="font-bold" x-text="t('Scenario running', 'السيناريو جاري')"></p>
        <p x-text="performanceScenario.phase"></p>
    </div>

    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3 text-sm text-red-900"
        x-show="performanceScenario.lastError">
        <p class="font-bold" x-text="t('Last run error', 'خطأ آخر تشغيل')"></p>
        <p x-text="performanceScenario.lastError"></p>
    </div>

    {{-- Aspect message --}}
    <div class="rounded-lg border bg-white p-3 text-sm text-slate-700"
        x-show="performanceScenario.stats?.aspect_message_en">
        <p x-text="lang === 'ar' ? performanceScenario.stats.aspect_message_ar : performanceScenario.stats.aspect_message_en"></p>
    </div>

    {{-- Empty state --}}
    <div class="rounded-lg border-2 border-slate-300 bg-slate-50 p-4 text-sm text-slate-700"
        x-show="performanceScenario.stats && !performanceHasMeasurements()">
        <p class="font-semibold" x-text="t('No measurements yet', 'لا توجد قياسات بعد')"></p>
        <p class="mt-1"
            x-text="t('Click Run full scenario above. php artisan serve must be running.', 'اضغط تشغيل السيناريو الكامل. يجب أن يعمل php artisan serve.')">
        </p>
    </div>

    {{-- Summary cards --}}
    <template x-if="performanceHasMeasurements()">
        <div class="space-y-3">
            <h5 class="font-semibold text-sm" x-text="t('Summary', 'ملخص')"></h5>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Total', 'الإجمالي')"></div>
                    <div class="text-xl font-bold" x-text="performanceScenario.stats.summary?.total_measurements ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Avg ms', 'متوسط ms')"></div>
                    <div class="text-xl font-bold" x-text="performanceScenario.stats.summary?.avg_duration_ms ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Max ms', 'أقصى ms')"></div>
                    <div class="text-xl font-bold" x-text="performanceScenario.stats.summary?.max_duration_ms ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Slow count', 'بطيء')"></div>
                    <div class="text-xl font-bold text-amber-600" x-text="performanceScenario.stats.summary?.slow_count ?? 0"></div>
                </div>
                <div class="rounded-lg border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Threshold ms', 'حد ms')"></div>
                    <div class="text-xl font-bold text-violet-700" x-text="performanceScenario.stats.slow_threshold_ms ?? 500"></div>
                </div>
            </div>
        </div>
    </template>

    {{-- Channel breakdown --}}
    <template x-if="performanceHasMeasurements() && performanceScenario.stats.by_channel">
        <div class="space-y-2">
            <h5 class="font-semibold text-sm" x-text="t('By channel', 'حسب القناة')"></h5>
            <div class="grid grid-cols-2 gap-3 text-sm">
                <template x-for="(data, channel) in performanceScenario.stats.by_channel" :key="channel">
                    <div class="rounded-lg border bg-white p-3">
                        <div class="font-semibold uppercase text-violet-800" x-text="channel"></div>
                        <div class="text-xs text-slate-500 mt-1">
                            <span x-text="t('Count', 'عدد')"></span>:
                            <strong x-text="data.count"></strong>
                            · <span x-text="t('Avg', 'متوسط')"></span>:
                            <strong x-text="data.avg_duration_ms + ' ms'"></strong>
                            · <span x-text="t('Max', 'أقصى')"></span>:
                            <strong x-text="data.max_duration_ms + ' ms'"></strong>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    {{-- Top routes --}}
    <template x-if="performanceScenario.stats?.top_routes?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Top routes (by avg ms)', 'أبطأ المسارات (متوسط ms)')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-48 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('Route', 'مسار')"></th>
                            <th class="px-3 py-2" x-text="t('Count', 'عدد')"></th>
                            <th class="px-3 py-2" x-text="t('Avg ms', 'متوسط')"></th>
                            <th class="px-3 py-2" x-text="t('Max ms', 'أقصى')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in performanceScenario.stats.top_routes" :key="row.name">
                            <tr class="border-t" :class="row.is_slow ? 'bg-amber-50' : ''">
                                <td class="px-3 py-2 font-mono text-xs" x-text="row.name"></td>
                                <td class="px-3 py-2" x-text="row.count"></td>
                                <td class="px-3 py-2 font-mono" x-text="row.avg_duration_ms"></td>
                                <td class="px-3 py-2 font-mono" x-text="row.max_duration_ms"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- Recent log --}}
    <template x-if="performanceScenario.stats?.recent?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Recent measurements', 'آخر القياسات')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('Channel', 'قناة')"></th>
                            <th class="px-3 py-2" x-text="t('Route', 'مسار')"></th>
                            <th class="px-3 py-2" x-text="t('ms', 'ms')"></th>
                            <th class="px-3 py-2" x-text="t('Status', 'حالة')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in (performanceScenario.stats.recent || []).slice(0, 15)" :key="row.name + (row.created_at || '')">
                            <tr class="border-t" :class="{
                                'bg-amber-50': performanceIsSlow(row.duration_ms),
                                'bg-red-50': performanceIsSlow(row.duration_ms) && row.duration_ms >= (performanceScenario.stats.slow_threshold_ms * 2)
                            }">
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.channel === 'job' ? 'bg-purple-200' : 'bg-violet-200'"
                                        x-text="row.channel"></span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs truncate max-w-[12rem]" x-text="row.name"></td>
                                <td class="px-3 py-2 font-mono" :class="performanceIsSlow(row.duration_ms) ? 'text-amber-700 font-bold' : ''"
                                    x-text="row.duration_ms"></td>
                                <td class="px-3 py-2" x-text="row.status_code ?? '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- Manual probe buttons --}}
    <div class="flex flex-wrap items-center gap-2 p-4 rounded-lg border bg-white text-sm">
        <span class="text-xs text-slate-500 w-full mb-1" x-text="t('Quick probes (single hit)', 'مسارات سريعة')"></span>
        <button type="button"
            @click="runPerformanceProbe('POST', '/api/buy-with-lock', { product_id: productId, quantity: 1 })"
            :disabled="performanceScenario.loading"
            class="rounded-lg border border-violet-300 px-3 py-1.5 hover:bg-violet-50 text-xs"
            x-text="t('Buy with lock', 'شراء مع lock')">
        </button>
        <button type="button"
            @click="runPerformanceProbe('GET', '/api/products/' + productId + '/cached')"
            :disabled="performanceScenario.loading"
            class="rounded-lg border border-violet-300 px-3 py-1.5 hover:bg-violet-50 text-xs"
            x-text="t('Cached product', 'منتج cached')">
        </button>
        <button type="button"
            @click="runPerformanceProbe('GET', '/api/benchmark/sales-report/slow?product_id=' + productId)"
            :disabled="performanceScenario.loading"
            class="rounded-lg border border-amber-300 px-3 py-1.5 hover:bg-amber-50 text-xs"
            x-text="t('Slow report', 'تقرير بطيء')">
        </button>
        <button type="button"
            @click="runPerformanceProbe('POST', '/api/checkout/acid', { product_id: productId, quantity: 1 })"
            :disabled="performanceScenario.loading"
            class="rounded-lg border border-violet-300 px-3 py-1.5 hover:bg-violet-50 text-xs"
            x-text="t('ACID checkout', 'checkout ACID')">
        </button>
    </div>

    {{-- CLI hint --}}
    <details class="rounded-lg border bg-white p-4">
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: curl with response header', 'اختياري: curl مع رأس الاستجابة')"></summary>
        <p class="text-xs text-slate-600 mt-2 font-mono">curl -i http://127.0.0.1:8000/api/products/1/cached</p>
        <p class="text-xs text-slate-500 mt-1" x-text="t('Look for X-Response-Time-Ms in response headers.', 'ابحث عن X-Response-Time-Ms في رؤوس الاستجابة.')"></p>
    </details>
</div>
