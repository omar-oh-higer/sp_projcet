{{-- Task 8: transaction integrity full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-rose-200 bg-rose-50/40 p-5">
    <h4 class="font-bold text-rose-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-rose-800"
        x-text="t('Reset → non-atomic fail (orphan) → cleanup → ACID fail (rollback) → compare', 'Reset → غير ذري (يتيم) → تنظيف → ACID (rollback) → مقارنة')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runIntegrityFullScenario({{ json_encode($taskId) }})"
            :disabled="integrityScenario.loading"
            class="rounded-lg bg-rose-600 text-white px-4 py-2 text-sm font-semibold hover:bg-rose-700 disabled:opacity-50">
            <span x-show="!integrityScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="integrityScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetIntegrityDemo()"
            class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm hover:bg-rose-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="fetchIntegrityStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-rose-700" x-show="integrityScenario.phase"
            x-text="integrityScenario.phase"></span>
    </div>

    {{-- Transaction flow card --}}
    <div class="rounded-lg border-2 border-amber-300 bg-amber-50/60 p-4">
        <h5 class="font-semibold text-sm mb-3 text-amber-900" x-text="t('Checkout steps', 'خطوات الدفع')"></h5>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="rounded-lg border-2 border-emerald-400 bg-emerald-50 px-3 py-2 font-medium"
                x-text="t('1. Payment', '1. الدفع')"></span>
            <span class="text-amber-600">→</span>
            <span class="rounded-lg border-2 border-emerald-400 bg-emerald-50 px-3 py-2 font-medium"
                x-text="t('2. Stock', '2. المخزون')"></span>
            <span class="text-amber-600">→</span>
            <span class="rounded-lg border-2 border-emerald-400 bg-emerald-50 px-3 py-2 font-medium"
                x-text="t('3. Order', '3. الطلب')"></span>
        </div>
        <p class="mt-3 text-xs text-amber-800"
            x-text="t('Simulated fail injects after selected step — non-atomic leaves orphans; ACID rolls back all.', 'الفشل المحاكى بعد الخطوة المختارة — غير ذري يترك يتامى؛ ACID يرجع عن الكل.')">
        </p>
    </div>

    {{-- Simulate controls --}}
    <div class="flex flex-wrap items-center gap-4 p-4 rounded-lg border bg-white text-sm">
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
        <button type="button"
            @click="runCheckout('non-atomic')"
            class="rounded-lg border border-amber-300 px-3 py-1.5 hover:bg-amber-50 text-xs"
            x-text="t('Run non-atomic once', 'تشغيل غير ذري')">
        </button>
        <button type="button"
            @click="runCheckout('acid')"
            class="rounded-lg border border-emerald-300 px-3 py-1.5 hover:bg-emerald-50 text-xs"
            x-text="t('Run ACID once', 'تشغيل ACID')">
        </button>
    </div>

    {{-- Product snapshot --}}
    <template x-if="integrityScenario.stats?.product_snapshot">
        <div class="rounded-lg border bg-white p-3 text-sm">
            <span x-text="t('Product', 'منتج')"></span> #<span x-text="integrityScenario.stats.product_snapshot.id"></span>
            · <span x-text="t('Stock', 'مخزون')"></span>:
            <strong x-text="integrityScenario.stats.product_snapshot.stock"></strong>
            · <span x-text="t('Price', 'سعر')"></span>:
            <strong x-text="integrityScenario.stats.product_snapshot.price_cents"></strong>
            <span x-text="integrityScenario.stats.currency ?? 'USD'"></span>
        </div>
    </template>

    {{-- DB audit row --}}
    <template x-if="integrityScenario.stats?.db_audit">
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Orphan payments', 'مدفوعات يتيمة')"></div>
                <div class="text-xl font-bold text-red-600" x-text="integrityScenario.stats.db_audit.orphan_payments ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Orders w/o payment', 'طلبات بدون دفع')"></div>
                <div class="text-xl font-bold text-amber-600" x-text="integrityScenario.stats.db_audit.orders_without_payment ?? 0"></div>
            </div>
        </div>
    </template>

    {{-- Metrics row --}}
    <template x-if="integrityScenario.stats?.metrics">
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Non-atomic failures', 'فشل غير ذري')"></div>
                <div class="text-xl font-bold text-amber-600" x-text="integrityScenario.stats.metrics.non_atomic_failures ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('ACID failures', 'فشل ACID')"></div>
                <div class="text-xl font-bold text-indigo-600" x-text="integrityScenario.stats.metrics.acid_failures ?? 0"></div>
            </div>
            <div class="rounded border bg-white p-3">
                <div class="text-slate-500" x-text="t('Integrity violations', 'خرق سلامة')"></div>
                <div class="text-xl font-bold text-red-600" x-text="integrityScenario.stats.metrics.integrity_violations ?? 0"></div>
            </div>
        </div>
    </template>

    {{-- Before vs After --}}
    <template x-if="integrityScenario.stats?.scenario_summary">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-amber-700 mb-1" x-text="t('Before — non-atomic', 'قبل — غير ذري')"></div>
                <div class="text-2xl font-bold text-red-600"
                    x-text="integrityScenario.stats.scenario_summary.max_orphan_payments_in_log ?? 0"></div>
                <div class="text-xs text-slate-500">
                    <span x-text="t('Max orphans in log', 'أقصى يتامى في السجل')"></span>
                    · <span x-text="t('Violations', 'انتهاكات')"></span>:
                    <strong x-text="integrityScenario.stats.scenario_summary.integrity_violations_total ?? 0"></strong>
                </div>
            </div>
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-emerald-700 mb-1" x-text="t('After — ACID rollback', 'بعد — ACID')"></div>
                <div class="text-2xl font-bold text-emerald-600"
                    x-text="integrityScenario.stats.scenario_summary.current_orphan_payments ?? 0"></div>
                <div class="text-xs text-slate-500">
                    <span x-text="t('Current orphans in DB', 'يتامى حالياً في DB')"></span>
                    · <span x-text="t('Final stock', 'مخزون نهائي')"></span>:
                    <strong x-text="integrityScenario.stats.scenario_summary.final_stock ?? '—'"></strong>
                </div>
            </div>
        </div>
    </template>

    {{-- Checkout log --}}
    <template x-if="integrityScenario.stats?.recent_checkouts?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Checkout log', 'سجل الدفع')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('#', '#')"></th>
                            <th class="px-3 py-2" x-text="t('Mode', 'وضع')"></th>
                            <th class="px-3 py-2" x-text="t('Status', 'حالة')"></th>
                            <th class="px-3 py-2" x-text="t('Violation', 'خرق')"></th>
                            <th class="px-3 py-2" x-text="t('Orphans', 'يتامى')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in integrityScenario.stats.recent_checkouts" :key="row.checkout_index + '-' + row.transaction_mode + '-' + row.recorded_at">
                            <tr class="border-t" :class="{
                                'bg-red-50': row.integrity_violation,
                                'bg-emerald-50': row.status === 'success',
                                'bg-indigo-50': row.transaction_mode === 'acid' && row.status === 'simulated_failure'
                            }">
                                <td class="px-3 py-2 font-mono" x-text="row.checkout_index ?? '—'"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="row.transaction_mode === 'non_atomic' ? 'bg-amber-200' : 'bg-emerald-200'"
                                        x-text="row.transaction_mode"></span>
                                </td>
                                <td class="px-3 py-2 font-medium" x-text="row.status"></td>
                                <td class="px-3 py-2" x-text="row.integrity_violation ? '✓' : '—'"></td>
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
        <summary class="font-semibold text-sm cursor-pointer" x-text="t('Optional: CLI demo', 'اختياري: CLI')"></summary>
        <p class="text-xs text-slate-600 mt-2 font-mono">
            php artisan checkout:integrity-demo --mode=non-atomic
        </p>
        <p class="text-xs text-slate-600 mt-1 font-mono">
            php artisan checkout:integrity-demo --mode=acid
        </p>
    </details>
</div>
