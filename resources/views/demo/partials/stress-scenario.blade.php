{{-- Task 9: stress testing full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-5">
    <h4 class="font-bold text-indigo-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-indigo-800"
        x-text="t('Reset → unsafe concurrent load → cleanup → safe ACID load → compare', 'Reset → unsafe → cleanup → safe → compare')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runStressFullScenario({{ json_encode($taskId) }})"
            :disabled="stressScenario.loading"
            class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">
            <span x-show="!stressScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="stressScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetStressDemo()"
            class="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm hover:bg-indigo-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchStressStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-indigo-700" x-show="stressScenario.phase"
            x-text="stressScenario.phase"></span>
    </div>

    {{-- What is N? --}}
    <div class="rounded-lg border-2 border-indigo-300 bg-white p-4 text-sm">
        <h5 class="font-semibold text-indigo-900 mb-2" x-text="t('What is N?', 'ما معنى N؟')"></h5>
        <p class="text-slate-700"
            x-text="t('N = concurrent virtual buyers. Http::pool fires N POST checkout requests at the same moment — not a sequential for loop.', 'N = عدد المشترين المتزامنين. Http::pool يرسل N طلب POST في نفس اللحظة — وليس حلقة for متسلسلة.')">
        </p>
        <ul class="mt-2 list-disc ms-5 text-xs text-slate-600 space-y-1">
            <li x-text="t('Demo stock is intentionally small (10) so N > stock creates competition.', 'مخزون العرض صغير (10) عمداً — N أكبر من المخزون يُظهر التنافس.')"></li>
            <li x-text="t('Safe (ACID): successes ≤ stock, integrity PASS, orphans = 0 expected.', 'Safe (ACID): نجاح ≤ مخزون، سلامة PASS، يتامى = 0 متوقع.')"></li>
            <li x-text="t('Unsafe (non-atomic): oversell / orphan payments / integrity FAIL possible.', 'Unsafe: oversell / يتامى / فشل سلامة محتمل.')"></li>
        </ul>
    </div>

    {{-- N selector --}}
    <div class="rounded-lg border bg-white p-4 text-sm">
        <h5 class="font-semibold mb-3" x-text="t('Concurrent users (N)', 'المستخدمون المتزامنون (N)')"></h5>
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <button type="button" @click="setStressUsersPreset(15)"
                class="rounded-lg border px-3 py-1.5 text-xs hover:bg-indigo-50"
                :class="stressScenario.concurrentUsers === 15 ? 'border-indigo-500 bg-indigo-50 font-semibold' : ''">
                15 <span x-text="t('(quick)', '(سريع)')"></span>
            </button>
            <button type="button" @click="setStressUsersPreset(50)"
                class="rounded-lg border px-3 py-1.5 text-xs hover:bg-indigo-50"
                :class="stressScenario.concurrentUsers === 50 ? 'border-indigo-500 bg-indigo-50 font-semibold' : ''">
                50
            </button>
            <button type="button" @click="setStressUsersPreset(100)"
                class="rounded-lg border px-3 py-1.5 text-xs hover:bg-indigo-50"
                :class="stressScenario.concurrentUsers === 100 ? 'border-indigo-500 bg-indigo-50 font-semibold' : ''">
                100 <span x-text="t('(lecture)', '(محاضرة)')"></span>
            </button>
            <label class="flex items-center gap-2 ms-2">
                <span class="text-slate-500" x-text="t('Custom', 'مخصص')"></span>
                <input type="number" min="1"
                    :max="stressUsersMax()"
                    x-model.number="stressScenario.concurrentUsers"
                    @change="clampStressUsers()"
                    class="w-20 rounded border-slate-300 text-sm font-mono">
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm font-mono text-indigo-800">
            <span class="rounded border bg-indigo-50 px-2 py-1" x-text="'N = ' + stressScenario.concurrentUsers"></span>
            <span class="text-indigo-500">×</span>
            <span class="rounded border bg-indigo-50 px-2 py-1">POST /api/checkout/…</span>
            <span class="text-indigo-500">→</span>
            <span class="rounded border bg-indigo-50 px-2 py-1"
                x-text="t('stock = ', 'مخزون = ') + (stressScenario.stats?.demo_stock ?? stressScenario.stats?.scenario_summary?.initial_demo_stock ?? 10)">
            </span>
        </div>
        <p class="mt-2 text-xs text-slate-500"
            x-text="t('Stress target:', 'هدف الضغط:') + ' ' + (stressScenario.stats?.effective_base_url ?? window.location.origin) + '. ' + t('Demo-run uses a background subprocess on this URL.', 'demo-run يستخدم subprocess على هذا URL.')">
        </p>
    </div>

    {{-- Background run in progress --}}
    <div class="rounded-lg border-2 border-indigo-300 bg-indigo-50 p-3 text-sm text-indigo-900"
        x-show="stressScenario.stats?.demo_run_in_progress || stressScenario.phase">
        <p class="font-bold" x-text="t('Stress run in progress', 'تشغيل الضغط جاري')"></p>
        <p x-text="stressScenario.phase || t('Background subprocess running…', 'subprocess يعمل…')"></p>
    </div>

    {{-- Empty state --}}
    <div class="rounded-lg border-2 border-slate-300 bg-slate-50 p-4 text-sm text-slate-700"
        x-show="stressScenario.stats && !stressHasRuns()">
        <p class="font-semibold" x-text="t('No stress run yet', 'لا يوجد تشغيل ضغط بعد')"></p>
        <p class="mt-1"
            x-text="t('Click Run full scenario above. php artisan serve must be running in another terminal.', 'اضغط تشغيل السيناريو الكامل. يجب أن يعمل php artisan serve في terminal آخر.')">
        </p>
    </div>

    {{-- Run error --}}
    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3 text-sm text-red-900"
        x-show="stressScenario.lastError">
        <p class="font-bold" x-text="t('Last run error', 'خطأ آخر تشغيل')"></p>
        <pre class="mt-1 text-xs whitespace-pre-wrap font-mono" x-text="stressScenario.lastError"></pre>
    </div>

    {{-- Server prerequisite --}}
    <div class="rounded-lg border-2 border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
        x-show="stressScenario.stats && (stressScenario.stats.server_reachable === false || stressScenario.stats.base_url_mismatch)">
        <p class="font-bold" x-text="t('Server / URL check', 'فحص الخادم / URL')"></p>
        <p x-text="lang === 'ar' ? stressScenario.stats.subprocess_hint_ar : stressScenario.stats.subprocess_hint_en"></p>
    </div>

    {{-- Connection failure --}}
    <div class="rounded-lg border-2 border-red-400 bg-red-50 p-3 text-sm text-red-900"
        x-show="stressScenario.stats?.connection_failure_hint_en">
        <p class="font-bold" x-text="t('All requests failed', 'فشلت كل الطلبات')"></p>
        <p x-text="lang === 'ar' ? stressScenario.stats.connection_failure_hint_ar : stressScenario.stats.connection_failure_hint_en"></p>
    </div>

    {{-- Manual demo controls --}}
    <div class="flex flex-wrap items-center gap-3 p-4 rounded-lg border bg-white text-sm">
        <button type="button"
            @click="runStressDemo('unsafe')"
            :disabled="stressScenario.loading"
            class="rounded-lg border border-amber-300 px-3 py-1.5 hover:bg-amber-50 text-xs"
            x-text="t('Run unsafe once', 'تشغيل unsafe')">
        </button>
        <button type="button"
            @click="runStressDemo('safe')"
            :disabled="stressScenario.loading"
            class="rounded-lg border border-emerald-300 px-3 py-1.5 hover:bg-emerald-50 text-xs"
            x-text="t('Run safe once', 'تشغيل safe')">
        </button>
        <span class="text-xs text-slate-500" x-show="stressScenario.stats?.last_concurrent_users"
            x-text="t('Last run N:', 'آخر N:') + ' ' + (stressScenario.stats?.last_concurrent_users ?? '—')">
        </span>
    </div>

    {{-- Product snapshot --}}
    <template x-if="stressScenario.stats?.product_snapshot">
        <div class="rounded-lg border bg-white p-3 text-sm">
            <span x-text="t('Product', 'منتج')"></span> #<span x-text="stressScenario.stats.product_snapshot.id"></span>
            · <span x-text="t('Stock', 'مخزون')"></span>:
            <strong x-text="stressScenario.stats.product_snapshot.stock"></strong>
        </div>
    </template>

    {{-- DB audit --}}
    <template x-if="stressScenario.stats?.db_audit">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Stock', 'مخزون')"></div>
                <div class="text-xl font-bold" x-text="stressScenario.stats.db_audit.stock ?? '—'"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Orphan payments', 'مدفوعات يتيمة')"></div>
                <div class="text-xl font-bold text-red-600" x-text="stressScenario.stats.db_audit.orphan_payments ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Success orders', 'طلبات ناجحة')"></div>
                <div class="text-xl font-bold" x-text="stressScenario.stats.db_audit.successful_orders ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Captured payments', 'مدفوعات')"></div>
                <div class="text-xl font-bold" x-text="stressScenario.stats.db_audit.captured_payments ?? 0"></div>
            </div>
        </div>
    </template>

    {{-- Last unsafe run metrics --}}
    <template x-if="stressScenario.stats?.last_report?.unsafe_scenario">
        <div>
            <h5 class="font-semibold text-sm mb-2 text-amber-800" x-text="t('Last unsafe run', 'آخر تشغيل unsafe')"></h5>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Success', 'نجاح')"></div>
                    <div class="text-xl font-bold text-emerald-600" x-text="stressScenario.stats.last_report.unsafe_scenario.success_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Failed', 'فشل')"></div>
                    <div class="text-xl font-bold text-red-600" x-text="stressScenario.stats.last_report.unsafe_scenario.failed_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Rejected 409', 'مرفوض 409')"></div>
                    <div class="text-xl font-bold text-amber-600" x-text="stressScenario.stats.last_report.unsafe_scenario.rejected_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Avg ms', 'متوسط ms')"></div>
                    <div class="text-xl font-bold" x-text="stressScenario.stats.last_report.unsafe_scenario.average_response_time_ms ?? '—'"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Pool ms', 'مدة pool')"></div>
                    <div class="text-xl font-bold" x-text="Math.round(stressScenario.stats.last_report.unsafe_scenario.pool_duration_ms ?? 0)"></div>
                </div>
            </div>
        </div>
    </template>

    {{-- Last safe run metrics --}}
    <template x-if="stressScenario.stats?.last_report?.safe_scenario">
        <div>
            <h5 class="font-semibold text-sm mb-2 text-emerald-800" x-text="t('Last safe run', 'آخر تشغيل safe')"></h5>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Success', 'نجاح')"></div>
                    <div class="text-xl font-bold text-emerald-600" x-text="stressScenario.stats.last_report.safe_scenario.success_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Failed', 'فشل')"></div>
                    <div class="text-xl font-bold text-red-600" x-text="stressScenario.stats.last_report.safe_scenario.failed_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Rejected 409', 'مرفوض 409')"></div>
                    <div class="text-xl font-bold text-amber-600" x-text="stressScenario.stats.last_report.safe_scenario.rejected_requests ?? 0"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Avg ms', 'متوسط ms')"></div>
                    <div class="text-xl font-bold" x-text="stressScenario.stats.last_report.safe_scenario.average_response_time_ms ?? '—'"></div>
                </div>
                <div class="rounded border bg-white p-3">
                    <div class="text-slate-500" x-text="t('Pool ms', 'مدة pool')"></div>
                    <div class="text-xl font-bold" x-text="Math.round(stressScenario.stats.last_report.safe_scenario.pool_duration_ms ?? 0)"></div>
                </div>
            </div>
        </div>
    </template>

    {{-- Single-scenario last run (when only one scenario in report) --}}
    <template x-if="stressScenario.stats?.last_report?.primary_scenario && !stressScenario.stats?.last_report?.unsafe_scenario && !stressScenario.stats?.last_report?.safe_scenario">
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Success', 'نجاح')"></div>
                <div class="text-xl font-bold text-emerald-600" x-text="stressScenario.stats.last_report.primary_scenario.success_requests ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Failed', 'فشل')"></div>
                <div class="text-xl font-bold text-red-600" x-text="stressScenario.stats.last_report.primary_scenario.failed_requests ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Rejected 409', 'مرفوض 409')"></div>
                <div class="text-xl font-bold text-amber-600" x-text="stressScenario.stats.last_report.primary_scenario.rejected_requests ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Avg ms', 'متوسط ms')"></div>
                <div class="text-xl font-bold" x-text="stressScenario.stats.last_report.primary_scenario.average_response_time_ms ?? '—'"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Pool ms', 'مدة pool')"></div>
                <div class="text-xl font-bold" x-text="Math.round(stressScenario.stats.last_report.primary_scenario.pool_duration_ms ?? 0)"></div>
            </div>
        </div>
    </template>

    {{-- Before vs After (only after runs) --}}
    <template x-if="stressScenario.stats?.scenario_summary && stressHasRuns()">
        <div class="space-y-3">
            <h5 class="font-semibold text-sm" x-text="t('Comparison summary', 'ملخص المقارنة')"></h5>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="rounded-lg border bg-white p-3 text-sm">
                    <div class="font-semibold text-amber-700 mb-1" x-text="t('Before — unsafe (non-atomic)', 'قبل — unsafe')"></div>
                    <div class="text-2xl font-bold" x-text="stressScenario.stats.scenario_summary.unsafe_success_total ?? '—'"></div>
                    <div class="text-xs text-slate-500">
                        <span x-text="t('Successes', 'نجاح')"></span>
                        · N: <strong x-text="stressScenario.stats.scenario_summary.unsafe_concurrent_users ?? '—'"></strong>
                        · <span x-text="t('Failed', 'فشل')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.unsafe_failed_total ?? '—'"></strong>
                        · <span x-text="t('Rejected', 'مرفوض')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.unsafe_rejected_total ?? '—'"></strong>
                        · <span x-text="t('Integrity', 'سلامة')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.unsafe_integrity_pass === true ? t('PASS', 'OK') : (stressScenario.stats.scenario_summary.unsafe_integrity_pass === false ? t('FAIL', 'FAIL') : '—')"></strong>
                        · <span x-text="t('Orphans', 'يتامى')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.unsafe_orphans_after ?? '—'"></strong>
                    </div>
                    <p class="mt-2 text-xs text-amber-800"
                        x-show="stressScenario.stats.scenario_summary.unsafe_message_en"
                        x-text="lang === 'ar' ? stressScenario.stats.scenario_summary.unsafe_message_ar : stressScenario.stats.scenario_summary.unsafe_message_en">
                    </p>
                </div>
                <div class="rounded-lg border bg-white p-3 text-sm">
                    <div class="font-semibold text-emerald-700 mb-1" x-text="t('After — safe (ACID)', 'بعد — safe')"></div>
                    <div class="text-2xl font-bold" x-text="stressScenario.stats.scenario_summary.safe_success_total ?? '—'"></div>
                    <div class="text-xs text-slate-500">
                        <span x-text="t('Successes', 'نجاح')"></span>
                        · N: <strong x-text="stressScenario.stats.scenario_summary.safe_concurrent_users ?? '—'"></strong>
                        · <span x-text="t('Failed', 'فشل')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.safe_failed_total ?? '—'"></strong>
                        · <span x-text="t('Rejected', 'مرفوض')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.safe_rejected_total ?? '—'"></strong>
                        · <span x-text="t('Integrity', 'سلامة')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.safe_integrity_pass === true ? t('PASS', 'OK') : (stressScenario.stats.scenario_summary.safe_integrity_pass === false ? t('FAIL', 'FAIL') : '—')"></strong>
                        · <span x-text="t('Orphans', 'يتامى')"></span>:
                        <strong x-text="stressScenario.stats.scenario_summary.safe_orphans_after ?? '—'"></strong>
                    </div>
                    <p class="mt-2 text-xs text-emerald-800"
                        x-show="stressScenario.stats.scenario_summary.safe_message_en"
                        x-text="lang === 'ar' ? stressScenario.stats.scenario_summary.safe_message_ar : stressScenario.stats.scenario_summary.safe_message_en">
                    </p>
                </div>
            </div>
        </div>
    </template>

    {{-- Run log --}}
    <template x-if="stressScenario.stats?.recent_runs?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Run log', 'سجل التشغيل')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('#', '#')"></th>
                            <th class="px-3 py-2" x-text="t('N', 'N')"></th>
                            <th class="px-3 py-2" x-text="t('Scenario', 'سيناريو')"></th>
                            <th class="px-3 py-2" x-text="t('Success', 'نجاح')"></th>
                            <th class="px-3 py-2" x-text="t('Failed', 'فشل')"></th>
                            <th class="px-3 py-2" x-text="t('409', '409')"></th>
                            <th class="px-3 py-2" x-text="t('Integrity', 'سلامة')"></th>
                            <th class="px-3 py-2" x-text="t('Orphans', 'يتامى')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in stressScenario.stats.recent_runs" :key="row.run_index + '-' + row.scenario + '-' + row.recorded_at">
                            <tr class="border-t" :class="{
                                'bg-red-50': row.system_crashed || ((row.success_requests ?? 0) === 0 && (row.connection_errors ?? 0) > 0),
                                'bg-amber-50': row.scenario === 'unsafe' && !row.system_crashed && (row.success_requests ?? 0) > 0 && !row.data_integrity_pass,
                                'bg-emerald-50': row.scenario === 'safe' && !row.system_crashed && (row.success_requests ?? 0) > 0 && row.data_integrity_pass
                            }">
                                <td class="px-3 py-2 font-mono" x-text="row.run_index ?? '—'"></td>
                                <td class="px-3 py-2 font-mono" x-text="row.concurrent_users ?? '—'"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.scenario === 'unsafe' ? 'bg-amber-200' : 'bg-emerald-200'"
                                        x-text="row.scenario"></span>
                                </td>
                                <td class="px-3 py-2" x-text="row.success_requests ?? '—'"></td>
                                <td class="px-3 py-2" x-text="row.failed_requests ?? '—'"></td>
                                <td class="px-3 py-2" x-text="row.rejected_requests ?? '—'"></td>
                                <td class="px-3 py-2"
                                    x-text="(row.success_requests ?? 0) === 0 && (row.connection_errors ?? 0) > 0 ? '—' : (row.data_integrity_pass ? '✓' : '✗')"></td>
                                <td class="px-3 py-2" x-text="row.orphan_payments_after ?? '—'"></td>
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
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: CLI beyond web max', 'اختياري: CLI فوق حد الويب')"></summary>
        <p class="text-xs text-slate-600 mt-2"
            x-text="t('Web demo caps at demo_users_max (100). For 200+ users use CLI directly.', 'الويب محدود بـ demo_users_max (100). لـ 200+ استخدم CLI.')">
        </p>
        <p class="text-xs text-slate-600 mt-2 font-mono">
            php artisan stress:concurrent --users=100 --scenario=both
        </p>
        <p class="text-xs text-slate-600 mt-1 font-mono">
            php artisan stress:concurrent --users=200 --scenario=safe
        </p>
    </details>
</div>
