{{-- Task 4: full tally scenario with seed, chunk worker table, summary --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-5">
    <h4 class="font-bold text-indigo-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-indigo-800" x-text="t('Step 1: Seed orders for TODAY → Step 2: Queue batch → Step 3: Workers write partial rows → Step 4: Finalize summary', '1: بذر طلبات اليوم → 2: طابور → 3: workers → 4: ملخص')"></p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runFullTallyScenario({{ json_encode($taskId) }})"
            :disabled="tallyScenario.loadingScenario"
            class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">
            <span x-show="!tallyScenario.loadingScenario" x-text="t('Run full scenario (today)', 'تشغيل السيناريو الكامل (اليوم)')"></span>
            <span x-show="tallyScenario.loadingScenario" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="seedTallyOrders(true)"
            :disabled="tallyScenario.loadingSeed"
            class="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm hover:bg-indigo-50 disabled:opacity-50"
            x-text="t('Seed today only', 'بذر اليوم فقط')">
        </button>
        <button type="button"
            @click="runQueuedTally({{ json_encode($taskId) }})"
            class="rounded-lg border border-emerald-400 bg-emerald-50 px-4 py-2 text-sm hover:bg-emerald-100"
            x-text="t('Queue tally only', 'تجميع طابور فقط')">
        </button>
        <button type="button"
            @click="refreshTallyStatus()"
            :disabled="tallyScenario.loadingRefresh"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white disabled:opacity-50">
            <span x-show="!tallyScenario.loadingRefresh" x-text="t('Refresh status', 'تحديث')"></span>
            <span x-show="tallyScenario.loadingRefresh" x-text="t('Refreshing…', 'جاري التحديث…')"></span>
        </button>
        <span class="text-xs text-slate-500" x-show="tallyScenario.lastRefreshedAt"
            x-text="t('Last refresh:', 'آخر تحديث:') + ' ' + tallyScenario.lastRefreshedAt"></span>
    </div>

    {{-- Seed result --}}
    <template x-if="tallyScenario.seedResult">
        <div class="rounded-lg bg-white border border-emerald-200 p-3 text-sm">
            <p class="font-medium text-emerald-800" x-text="tallyScenario.seedResult.message"></p>
            <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-2 text-xs">
                <div><span class="text-slate-500" x-text="t('Sale date', 'التاريخ')"></span>: <strong x-text="tallyScenario.seedResult.sale_date"></strong></div>
                <div><span class="text-slate-500" x-text="t('Orders today', 'طلبات اليوم')"></span>: <strong x-text="tallyScenario.seedResult.orders_for_date"></strong></div>
                <div><span class="text-slate-500" x-text="t('Chunk size', 'حجم الجزء')"></span>: <strong x-text="tallyScenario.seedResult.chunk_size"></strong></div>
                <div><span class="text-slate-500" x-text="t('Expected chunks', 'أجزاء متوقعة')"></span>: <strong x-text="tallyScenario.seedResult.expected_chunks_if_tally_now"></strong></div>
            </div>
        </div>
    </template>

    {{-- Batch progress --}}
    <template x-if="tallyScenario.batchId || tallyScenario.batchStatus">
        <div class="rounded-lg bg-white border p-4 space-y-3">
            <div class="flex flex-wrap gap-4 text-sm">
                <div>
                    <span class="text-slate-500" x-text="t('batch_id', 'batch_id')"></span>:
                    <code class="text-xs bg-slate-100 px-1 rounded" x-text="tallyScenario.batchId || tallyScenario.batchStatus?.batch_id || '—'"></code>
                </div>
                <div x-show="tallyScenario.expectedChunks || tallyScenario.batchStatus?.expected_chunks">
                    <span x-text="t('Chunks', 'أجزاء')"></span>:
                    <strong x-text="(tallyScenario.batchStatus?.completed_chunks ?? 0) + ' / ' + (tallyScenario.expectedChunks || tallyScenario.batchStatus?.expected_chunks || '?')"></strong>
                </div>
            </div>
            <div class="metric-bar-track h-4">
                <div class="metric-bar-fill bg-indigo-500 h-4"
                    :style="'width:' + (tallyScenario.batchStatus?.progress_percent ?? 0) + '%'"></div>
            </div>
            <p class="text-xs text-slate-500"
                x-show="tallyScenario.batchStatus?.waiting_for_workers"
                x-text="t('Processing… ensure php artisan queue:work is running, then Refresh.', 'جاري المعالجة… تأكد من queue:work ثم حدّث')">
            </p>
            <p class="text-xs text-slate-500"
                x-show="tallyScenario.batchStatus && !tallyScenario.batchStatus.finalize_ready && !tallyScenario.batchStatus.finalize_pending && !tallyScenario.batchStatus.waiting_for_workers"
                x-text="t('Waiting for queue workers… start php artisan queue:work (2+ terminals)', 'انتظر workers… شغّل queue:work')">
            </p>
            <p class="text-xs text-amber-700 font-medium"
                x-show="tallyScenario.batchStatus?.finalize_pending"
                x-text="t('All chunks done — Finalize job queued. Keep queue:work running or click Refresh.', 'الأجزاء اكتملت — انتظر Finalize أو اضغط تحديث')">
            </p>
            <p class="text-xs text-red-600" x-show="tallyScenario.refreshError" x-text="tallyScenario.refreshError"></p>
        </div>
    </template>

    {{-- Worker / chunk lecture view --}}
    @include('demo.partials.tally-scenario-slots')

    {{-- Final summary --}}
    <template x-if="tallyScenario.batchStatus?.summary">
        <div class="rounded-lg border-2 border-emerald-300 bg-emerald-50 p-4">
            <h5 class="font-bold text-emerald-900 mb-3" x-text="t('Final summary (daily_sales_summaries)', 'الملخص النهائي')"></h5>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div class="rounded bg-white p-3 border">
                    <div class="text-slate-500 text-xs" x-text="t('Orders', 'طلبات')"></div>
                    <div class="text-2xl font-bold" x-text="tallyScenario.batchStatus.summary.successful_order_count"></div>
                </div>
                <div class="rounded bg-white p-3 border">
                    <div class="text-slate-500 text-xs" x-text="t('Total quantity', 'كمية')"></div>
                    <div class="text-2xl font-bold" x-text="tallyScenario.batchStatus.summary.total_quantity"></div>
                </div>
                <div class="rounded bg-white p-3 border">
                    <div class="text-slate-500 text-xs" x-text="t('Mode', 'وضع')"></div>
                    <div class="text-xs font-mono font-bold" x-text="tallyScenario.batchStatus.summary.processing_mode"></div>
                </div>
                <div class="rounded bg-white p-3 border">
                    <div class="text-slate-500 text-xs" x-text="t('DB orders (verify)', 'طلبات DB')"></div>
                    <div class="text-2xl font-bold" x-text="tallyScenario.batchStatus.orders_in_db_for_date"></div>
                </div>
            </div>
            <p class="mt-2 text-xs text-emerald-800"
                x-show="tallyScenario.batchStatus.chunks_match_orders"
                x-text="t('✓ Partial chunk sums match final summary', '✓ مجموع الأجزاء = الملخص النهائي')">
            </p>
        </div>
    </template>
</div>
