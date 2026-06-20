{{-- Task 6: distributed caching full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-violet-200 bg-violet-50/40 p-5">
    <h4 class="font-bold text-violet-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-violet-800"
        x-text="t('Reset → 5× direct (DB) → 2× cached (miss+hit) → purchase (invalidate) → 1× cached (miss)', 'Reset → 5× direct → 2× cached → شراء → cached miss')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runCacheFullScenario({{ json_encode($taskId) }})"
            :disabled="cacheScenario.loading"
            class="rounded-lg bg-violet-600 text-white px-4 py-2 text-sm font-semibold hover:bg-violet-700 disabled:opacity-50">
            <span x-show="!cacheScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="cacheScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetCacheDemo()"
            class="rounded-lg border border-violet-300 bg-white px-4 py-2 text-sm hover:bg-violet-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchCacheStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-violet-700" x-show="cacheScenario.phase"
            x-text="cacheScenario.phase"></span>
    </div>

    {{-- Redis bypass warning --}}
    <div class="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        x-show="cacheScenario.stats && cacheScenario.stats.redis_reachable === false">
        <p class="font-bold" x-text="t('Redis unavailable', 'Redis غير متاح')"></p>
        <p x-text="lang === 'ar' ? cacheScenario.stats.redis_hint_ar : cacheScenario.stats.redis_hint_en"></p>
    </div>

    {{-- Redis slot card --}}
    <template x-if="cacheScenario.stats">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Redis slot (selected product)', 'مكان Redis للمنتج')"></h5>
            <div class="rounded-lg border-2 p-4 transition-all max-w-md"
                :class="{
                    'border-slate-300 bg-slate-50': !cacheScenario.stats.redis_key_populated,
                    'border-emerald-400 bg-emerald-50 ring-2 ring-emerald-200': cacheScenario.stats.redis_key_populated && cacheScenario.stats.redis_reachable,
                    'border-amber-300 bg-amber-50': !cacheScenario.stats.redis_reachable
                }">
                <div class="font-mono text-xs text-slate-600 break-all" x-text="cacheScenario.stats.cache_key_example"></div>
                <div class="mt-2 flex items-center gap-2">
                    <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase"
                        :class="cacheScenario.stats.redis_key_populated ? 'bg-emerald-200 text-emerald-900' : 'bg-slate-200 text-slate-700'"
                        x-text="cacheScenario.stats.redis_key_populated
                            ? t('FILLED', 'ممتلئ')
                            : t('EMPTY', 'فارغ')">
                    </span>
                    <span class="text-xs text-slate-500">
                        TTL: <span x-text="cacheScenario.stats.ttl_seconds"></span>s ·
                        store: <span x-text="cacheScenario.stats.redis_store"></span>
                    </span>
                </div>
            </div>
        </div>
    </template>

    {{-- Metrics row --}}
    <template x-if="cacheScenario.stats?.metrics">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Hits', 'إصابات')"></div>
                <div class="text-xl font-bold text-emerald-600" x-text="cacheScenario.stats.metrics.cache_hits ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Misses', 'إخفاقات')"></div>
                <div class="text-xl font-bold text-amber-600" x-text="cacheScenario.stats.metrics.cache_misses ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Hit rate %', 'نسبة الإصابة')"></div>
                <div class="text-xl font-bold" x-text="(cacheScenario.stats.metrics.hit_rate_percent ?? 0) + '%'"></div>
            </div>
            <div class="rounded border bg-white p-3" x-show="(cacheScenario.stats.metrics.cache_bypasses ?? 0) > 0">
                <div class="text-slate-500" x-text="t('Bypasses', 'تجاوز')"></div>
                <div class="text-xl font-bold text-red-600" x-text="cacheScenario.stats.metrics.cache_bypasses ?? 0"></div>
            </div>
        </div>
    </template>

    {{-- Before vs After summary --}}
    <template x-if="cacheScenario.stats?.scenario_summary">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-red-700 mb-1" x-text="t('Before — direct DB', 'قبل — DB مباشر')"></div>
                <div class="text-2xl font-bold" x-text="cacheScenario.stats.scenario_summary.direct_db_queries ?? 0"></div>
                <div class="text-xs text-slate-500" x-text="t('DB queries in recent log', 'استعلامات DB في السجل')"></div>
            </div>
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-emerald-700 mb-1" x-text="t('After — Cache-Aside', 'بعد — Cache-Aside')"></div>
                <div class="text-2xl font-bold" x-text="cacheScenario.stats.scenario_summary.cached_db_queries ?? 0"></div>
                <div class="text-xs text-slate-500" x-text="t('DB queries (misses + invalidation only)', 'استعلامات DB (miss + invalidation)')"></div>
            </div>
        </div>
    </template>

    {{-- Lookup log --}}
    <template x-if="cacheScenario.stats?.recent_lookups?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Lookup log', 'سجل الاستعلامات')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('#', '#')"></th>
                            <th class="px-3 py-2" x-text="t('Endpoint', 'نقطة')"></th>
                            <th class="px-3 py-2" x-text="t('Result', 'نتيجة')"></th>
                            <th class="px-3 py-2" x-text="t('DB queries', 'DB')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in cacheScenario.stats.recent_lookups" :key="row.lookup_index + '-' + row.endpoint + '-' + row.recorded_at">
                            <tr class="border-t" :class="{
                                'bg-red-50': row.endpoint === 'direct',
                                'bg-emerald-50': row.cache_result === 'hit',
                                'bg-amber-50': row.cache_result === 'miss',
                                'bg-orange-50': row.cache_result === 'bypass'
                            }">
                                <td class="px-3 py-2 font-mono" x-text="row.lookup_index ?? '—'"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.endpoint === 'direct' ? 'bg-red-200' : 'bg-violet-200'"
                                        x-text="row.endpoint"></span>
                                </td>
                                <td class="px-3 py-2 font-medium" x-text="row.cache_result ?? '—'"></td>
                                <td class="px-3 py-2" x-text="row.db_queries ?? 0"></td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="lang === 'ar' ? row.message_ar : row.message_en"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- Warm popular (collapsed) --}}
    <details class="rounded-lg border bg-white p-4">
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: warm popular products', 'اختياري: تسخين المنتجات الشائعة')"></summary>
        <p class="text-xs text-slate-600 mt-2 mb-3"
            x-text="t('POST /api/cache/warm-popular — pre-load popular IDs before traffic spike.', 'POST /api/cache/warm-popular — تحميل مسبق قبل الذروة')">
        </p>
        <button type="button"
            @click="warmCache()"
            class="rounded-lg border border-violet-300 px-3 py-2 text-sm hover:bg-violet-50"
            x-text="t('Warm popular', 'تسخين')">
        </button>
    </details>
</div>
