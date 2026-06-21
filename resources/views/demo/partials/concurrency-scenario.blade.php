{{-- Task 7: concurrency control full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-rose-200 bg-rose-50/40 p-5">
    <h4 class="font-bold text-rose-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-rose-800"
        x-text="t('Reset → optimistic conflicts (snapshot) → restore stock → distributed stress', 'Reset → optimistic → distributed')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runConcurrencyFullScenario({{ json_encode($taskId) }})"
            :disabled="concurrencyScenario.loading"
            class="rounded-lg bg-rose-600 text-white px-4 py-2 text-sm font-semibold hover:bg-rose-700 disabled:opacity-50">
            <span x-show="!concurrencyScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="concurrencyScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetConcurrencyDemo()"
            class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm hover:bg-rose-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchConcurrencyStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-rose-700" x-show="concurrencyScenario.phase"
            x-text="concurrencyScenario.phase"></span>
    </div>

    {{-- Redis warning --}}
    <div class="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        x-show="concurrencyScenario.stats && concurrencyScenario.stats.redis_reachable === false">
        <p class="font-bold" x-text="t('Redis unavailable', 'Redis غير متاح')"></p>
        <p x-text="lang === 'ar' ? concurrencyScenario.stats.redis_hint_ar : concurrencyScenario.stats.redis_hint_en"></p>
    </div>

    {{-- Lock slot --}}
    <template x-if="concurrencyScenario.stats">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Redis lock key (selected product)', 'مفتاح قفل Redis')"></h5>
            <div class="rounded-lg border-2 p-4 transition-all max-w-md"
                :class="concurrencyScenario.stats.redis_reachable
                    ? 'border-emerald-400 bg-emerald-50'
                    : 'border-amber-300 bg-amber-50'">
                <div class="font-mono text-xs text-slate-600 break-all" x-text="concurrencyScenario.stats.lock_key_example"></div>
                <div class="mt-2 text-xs text-slate-500">
                    store: <span x-text="concurrencyScenario.stats.lock_store"></span> ·
                    TTL: <span x-text="concurrencyScenario.stats.lock_ttl_seconds"></span>s ·
                    block: <span x-text="concurrencyScenario.stats.lock_block_seconds"></span>s
                </div>
                <template x-if="concurrencyScenario.stats.product_snapshot">
                    <div class="mt-2 text-sm">
                        <span x-text="t('Stock', 'مخزون')"></span>:
                        <strong x-text="concurrencyScenario.stats.product_snapshot.stock"></strong>
                        · <span x-text="t('Version', 'إصدار')"></span>:
                        <strong x-text="concurrencyScenario.stats.product_snapshot.version"></strong>
                    </div>
                </template>
            </div>
        </div>
    </template>

    {{-- Metrics row --}}
    <template x-if="concurrencyScenario.stats?.metrics">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Opt. conflicts', 'تعارض تفاؤلي')"></div>
                <div class="text-xl font-bold text-amber-600" x-text="concurrencyScenario.stats.metrics.optimistic_conflicts ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Opt. successes', 'نجاح تفاؤلي')"></div>
                <div class="text-xl font-bold text-emerald-600" x-text="concurrencyScenario.stats.metrics.optimistic_successes ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Lock acquired', 'قفل OK')"></div>
                <div class="text-xl font-bold text-indigo-600" x-text="concurrencyScenario.stats.metrics.lock_acquired ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Distributed OK', 'موزع OK')"></div>
                <div class="text-xl font-bold text-emerald-700" x-text="concurrencyScenario.stats.metrics.distributed_successes ?? 0"></div>
            </div>
        </div>
    </template>

    {{-- Before vs After --}}
    <template x-if="concurrencyScenario.stats?.scenario_summary">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-amber-700 mb-1" x-text="t('Before — optimistic', 'قبل — تفاؤلي')"></div>
                <div class="text-2xl font-bold" x-text="concurrencyScenario.stats.scenario_summary.optimistic_conflicts_total ?? 0"></div>
                <div class="text-xs text-slate-500">
                    <span x-text="t('Conflicts', 'تعارضات')"></span>
                    · <span x-text="t('Successes', 'نجاح')"></span>:
                    <strong x-text="concurrencyScenario.stats.scenario_summary.optimistic_successes_total ?? 0"></strong>
                </div>
            </div>
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-emerald-700 mb-1" x-text="t('After — distributed lock', 'بعد — قفل موزع')"></div>
                <div class="text-2xl font-bold" x-text="concurrencyScenario.stats.scenario_summary.distributed_successes_total ?? 0"></div>
                <div class="text-xs text-slate-500">
                    <span x-text="t('Final stock', 'مخزون نهائي')"></span>:
                    <strong x-text="concurrencyScenario.stats.scenario_summary.final_stock ?? '—'"></strong>
                </div>
            </div>
        </div>
    </template>

    {{-- Attempt log --}}
    <template x-if="concurrencyScenario.stats?.recent_attempts?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Attempt log', 'سجل المحاولات')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('#', '#')"></th>
                            <th class="px-3 py-2" x-text="t('Strategy', 'استراتيجية')"></th>
                            <th class="px-3 py-2" x-text="t('Outcome', 'نتيجة')"></th>
                            <th class="px-3 py-2" x-text="t('Stock', 'مخزون')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in concurrencyScenario.stats.recent_attempts" :key="row.attempt_index + '-' + row.strategy + '-' + row.recorded_at">
                            <tr class="border-t" :class="{
                                'bg-amber-50': row.outcome === 'version_conflict',
                                'bg-emerald-50': row.outcome === 'success',
                                'bg-red-50': row.outcome === 'lock_timeout'
                            }">
                                <td class="px-3 py-2 font-mono" x-text="row.attempt_index ?? '—'"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.strategy === 'optimistic' ? 'bg-amber-200' : 'bg-indigo-200'"
                                        x-text="row.strategy"></span>
                                </td>
                                <td class="px-3 py-2 font-medium" x-text="row.outcome"></td>
                                <td class="px-3 py-2" x-text="row.stock_after ?? '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="lang === 'ar' ? row.message_ar : row.message_en"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- CLI hint --}}
    <details class="rounded-lg border bg-white p-4">
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: CLI stress', 'اختياري: ضغط CLI')"></summary>
        <p class="text-xs text-slate-600 mt-2 font-mono">
            php artisan concurrency:stress --strategy=optimistic --requests=20
        </p>
        <p class="text-xs text-slate-600 mt-1 font-mono">
            php artisan concurrency:stress --strategy=distributed --requests=20
        </p>
    </details>
</div>
