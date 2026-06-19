    {{-- Worker pool + chunk slots (lecture view) --}}
    <template x-if="tallyScenario.batchStatus?.chunk_slots?.length">
        <div class="space-y-4">
            <div class="rounded-lg border-2 border-red-400 bg-red-50 p-3 text-sm text-red-900"
                x-show="tallyScenario.batchStatus.worker_tracking_ok === false">
                <p class="font-bold" x-text="t('Worker tracking missing', 'تتبع الـ workers غير موجود')"></p>
                <p x-text="lang === 'ar' ? tallyScenario.batchStatus.worker_tracking_hint_ar : tallyScenario.batchStatus.worker_tracking_hint_en"></p>
            </div>

            <div class="rounded-lg bg-white border border-indigo-200 p-3 text-sm">
                <p class="font-medium text-indigo-900 mb-1" x-text="t('Thread pool (your queue:work terminals)', 'مجموعة queue:work')"></p>
                <div class="grid grid-cols-2 md:grid-cols-5 gap-2 text-xs mb-2">
                    <div><span class="text-slate-500" x-text="t('Terminals (.env)', 'terminals')"></span>: <strong x-text="tallyScenario.batchStatus.demo_worker_count"></strong></div>
                    <div><span class="text-slate-500" x-text="t('Chunks', 'chunks')"></span>: <strong x-text="tallyScenario.batchStatus.expected_chunks"></strong></div>
                    <div><span class="text-slate-500" x-text="t('Semaphore cap', 'حد التزامن')"></span>: <strong x-text="tallyScenario.batchStatus.max_concurrent_chunks"></strong></div>
                    <div><span class="text-slate-500" x-text="t('Busy now', 'نشط')"></span>: <strong x-text="tallyScenario.batchStatus.active_worker_count ?? 0"></strong></div>
                    <div><span class="text-slate-500" x-text="t('Ran twice', 'مرتين')"></span>: <strong x-text="tallyScenario.batchStatus.double_duty_worker_number ? ('Worker ' + tallyScenario.batchStatus.double_duty_worker_number) : '—'"></strong></div>
                </div>
            </div>

            {{-- Fixed terminal slots (4 queue:work windows) --}}
            <template x-if="tallyScenario.batchStatus.queue_terminals?.length">
                <div>
                    <h5 class="font-semibold text-sm mb-2" x-text="t('Running workers (one terminal = one worker)', 'Workers قيد التشغيل')"></h5>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
                        <template x-for="term in tallyScenario.batchStatus.queue_terminals" :key="term.terminal_number">
                            <div class="rounded-lg border p-3 text-sm"
                                :class="{
                                    'border-blue-500 bg-blue-50 ring-2 ring-blue-200': term.status === 'busy',
                                    'border-emerald-300 bg-emerald-50': term.status === 'idle' && term.chunks_handled?.length,
                                    'border-amber-300 bg-amber-50': term.status === 'waiting',
                                    'border-slate-200 bg-white': term.status === 'idle' && !term.chunks_handled?.length
                                }">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold" x-text="term.worker_label"></span>
                                    <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase"
                                        :class="{
                                            'bg-blue-600 text-white animate-pulse': term.status === 'busy',
                                            'bg-emerald-200 text-emerald-900': term.status === 'idle' && term.chunks_handled?.length,
                                            'bg-amber-200 text-amber-900': term.status === 'waiting',
                                            'bg-slate-200 text-slate-600': term.status === 'idle' && !term.chunks_handled?.length
                                        }"
                                        x-text="term.status"></span>
                                </div>
                                <p class="text-xs mt-2 text-slate-600" x-text="lang === 'ar' ? term.message_ar : term.message_en"></p>
                                <p class="text-[10px] mt-1 text-slate-400 font-mono" x-show="term.worker_pid" x-text="'PID ' + term.worker_pid"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            {{-- All chunk slots --}}
            <h5 class="font-semibold text-sm" x-text="t('Each chunk → which worker completed it', 'كل chunk → أي worker')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left">
                        <tr>
                            <th class="px-3 py-2" x-text="t('Chunk', 'جزء')"></th>
                            <th class="px-3 py-2" x-text="t('Status', 'حالة')"></th>
                            <th class="px-3 py-2" x-text="t('queue:work', 'worker')"></th>
                            <th class="px-3 py-2" x-text="t('Orders', 'طلبات')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="slot in tallyScenario.batchStatus.chunk_slots" :key="slot.chunk_index">
                            <tr class="border-t" :class="{
                                'bg-emerald-50': slot.status === 'completed',
                                'bg-blue-50': slot.status === 'running',
                                'bg-slate-50': slot.status === 'queued'
                            }">
                                <td class="px-3 py-2 font-mono text-xs" x-text="slot.chunk_label"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-2 py-0.5 text-xs font-medium uppercase"
                                        :class="{
                                            'bg-emerald-200 text-emerald-900': slot.status === 'completed',
                                            'bg-blue-200 text-blue-900 animate-pulse': slot.status === 'running',
                                            'bg-slate-200 text-slate-600': slot.status === 'queued'
                                        }"
                                        x-text="slot.status"></span>
                                </td>
                                <td class="px-3 py-2 font-medium" x-text="slot.worker_label"></td>
                                <td class="px-3 py-2" x-text="slot.order_count ?? '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="lang === 'ar' ? slot.message_ar : slot.message_en"></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-slate-50 font-semibold border-t" x-show="tallyScenario.batchStatus.partial_totals?.order_count">
                        <tr>
                            <td class="px-3 py-2" colspan="3" x-text="t('Completed partial totals', 'مجموع المكتمل')"></td>
                            <td class="px-3 py-2" x-text="tallyScenario.batchStatus.partial_totals?.order_count"></td>
                            <td class="px-3 py-2" x-text="tallyScenario.batchStatus.partial_totals?.total_quantity"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </template>
