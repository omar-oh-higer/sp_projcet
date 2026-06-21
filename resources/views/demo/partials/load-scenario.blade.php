{{-- Task 5: load distribution full scenario --}}
<div class="mt-6 space-y-4 rounded-xl border-2 border-indigo-200 bg-indigo-50/40 p-5">
    <h4 class="font-bold text-indigo-900" x-text="t('Full scenario (recommended)', 'السيناريو الكامل (موصى به)')"></h4>
    <p class="text-sm text-indigo-800"
        x-text="t('Probe live nodes → 9× single → 9× balanced (real HTTP when multi-server running) → server-2 down → 6× balanced', 'فحص العقد → 9× single → 9× balanced → server-2 معطّل → 6× balanced')">
    </p>

    <div class="flex flex-wrap gap-2 items-center">
        <button type="button"
            @click="runLoadFullScenario({{ json_encode($taskId) }})"
            :disabled="loadScenario.loading"
            class="rounded-lg bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">
            <span x-show="!loadScenario.loading" x-text="t('Run full scenario', 'تشغيل السيناريو الكامل')"></span>
            <span x-show="loadScenario.loading" x-text="t('Running…', 'جاري…')"></span>
        </button>
        <button type="button"
            @click="resetLoadDemo()"
            class="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm hover:bg-indigo-50"
            x-text="t('Reset', 'إعادة تعيين')">
        </button>
        <button type="button"
            @click="probeLoadNodes(true)"
            class="rounded-lg border border-indigo-300 bg-white px-4 py-2 text-sm hover:bg-indigo-50"
            x-text="t('Probe nodes', 'فحص العقد')">
        </button>
        <button type="button"
            @click="fetchLoadStatus()"
            class="rounded-lg border px-3 py-2 text-sm hover:bg-white"
            x-text="t('Refresh stats', 'تحديث')">
        </button>
        <span class="text-xs font-medium text-indigo-700" x-show="loadScenario.phase"
            x-text="loadScenario.phase"></span>
    </div>

    {{-- Mode banner --}}
    <div class="rounded-lg border-2 p-3 text-sm"
        :class="{
            'border-emerald-300 bg-emerald-50 text-emerald-900': loadScenario.scenarioMode === 'live',
            'border-amber-300 bg-amber-50 text-amber-900': loadScenario.scenarioMode === 'degraded',
            'border-slate-300 bg-slate-50 text-slate-700': loadScenario.scenarioMode === 'simulated'
        }">
        <p class="font-bold" x-text="t('Scenario mode', 'وضع السيناريو')"></p>
        <p x-show="loadScenario.scenarioMode === 'live'"
            x-text="t('LIVE — real HTTP to worker nodes on ports 8000–8002. Stopping a node affects routing.', 'LIVE — HTTP حقيقي للعقد. إيقاف node يؤثر على التوجيه.')"></p>
        <p x-show="loadScenario.scenarioMode === 'degraded'"
            x-text="t('DEGRADED — some nodes unreachable; balancer skips dead ports.', 'متدهور — بعض العقد غير متاحة؛ يتخطى الميتة.')"></p>
        <p x-show="loadScenario.scenarioMode === 'simulated'"
            x-text="t('SIMULATED — only gateway (8000) reachable. Run .\\scripts\\start-multi-server.ps1 for live HTTP.', 'محاكاة — شغّل start-multi-server.ps1 للـ HTTP الحقيقي.')"></p>
    </div>

    {{-- Education --}}
    <div class="rounded-lg border-2 border-indigo-300 bg-white p-4 text-sm">
        <h5 class="font-semibold text-indigo-900 mb-2" x-text="t('Registry vs live probe vs simulated', 'السجل vs الفحص الحي vs المحاكاة')"></h5>
        <ul class="list-disc ms-5 text-xs text-slate-600 space-y-1">
            <li x-text="t('Registry health: cache flag from Mark down/up or probe sync — controls Round Robin pool.', 'صحة السجل: من Mark down/up أو مزامنة الفحص.')"></li>
            <li x-text="t('Live probe: GET /up on each port — detects when you stop php artisan serve on 8001.', 'فحص حي: GET /up — يكتشف إيقاف serve على 8001.')"></li>
            <li x-text="t('Simulated routing (route-*): records virtual hits without HTTP — fallback when only 8000 runs.', 'محاكاة route-*: بدون HTTP — عند تشغيل 8000 فقط.')"></li>
            <li x-text="t('Real routing (process-*): gateway forwards HTTP; unreachable nodes auto-marked down + retried.', 'process-*: HTTP حقيقي؛ العقد الميتة تُتخطى تلقائياً.')"></li>
        </ul>
    </div>

    <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3 text-sm text-red-900"
        x-show="loadScenario.lastProbeError">
        <p class="font-bold" x-text="t('Scenario error', 'خطأ السيناريو')"></p>
        <p x-text="loadScenario.lastProbeError"></p>
    </div>

    {{-- Server rack --}}
    <template x-if="loadScenario.stats?.servers?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Server rack', 'الخوادم')"></h5>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <template x-for="srv in loadScenario.stats.servers" :key="srv.id">
                    <div class="rounded-lg border p-3 text-sm transition-all"
                        :class="{
                            'border-emerald-400 bg-emerald-50 ring-2 ring-emerald-300': loadScenario.stats.last_hit_server === srv.id,
                            'border-red-300 bg-red-50 opacity-90': srv.live_reachable === false,
                            'border-amber-300 bg-amber-50': srv.live_reachable === true && !srv.registry_healthy,
                            'border-slate-200 bg-white': srv.live_reachable !== false && srv.registry_healthy && loadScenario.stats.last_hit_server !== srv.id
                        }">
                        <div class="flex justify-between items-center flex-wrap gap-1">
                            <span class="font-mono font-semibold" x-text="srv.label"></span>
                            <div class="flex gap-1 flex-wrap">
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                    :class="srv.registry_healthy ? 'bg-emerald-200 text-emerald-900' : 'bg-red-200 text-red-900'"
                                    x-text="'REG: ' + (srv.registry_healthy ? 'up' : 'down')"></span>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-bold uppercase"
                                    :class="srv.live_reachable === true ? 'bg-blue-200 text-blue-900' : (srv.live_reachable === false ? 'bg-red-300 text-red-950' : 'bg-slate-200 text-slate-600')"
                                    x-text="srv.live_reachable === null ? 'LIVE: ?' : ('LIVE: ' + (srv.live_reachable ? 'up' : 'down'))"></span>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-slate-600">
                            <span x-text="t('Hits', 'ضربات')"></span>:
                            <strong x-text="srv.hits"></strong>
                            (<span x-text="srv.share_percent"></span>%)
                        </div>
                        <div class="mt-1 metric-bar-track h-2">
                            <div class="metric-bar-fill bg-indigo-500 h-2"
                                :style="'width:' + barWidth(srv.hits, maxServerHits(loadScenario.stats.servers))"></div>
                        </div>
                        <div class="mt-2 flex gap-2 text-xs">
                            <button type="button" @click="setServerHealth(srv.id, false)" class="text-red-600 underline" x-text="t('Mark down', 'تعطيل')"></button>
                            <button type="button" @click="setServerHealth(srv.id, true)" class="text-emerald-600 underline" x-text="t('Mark up', 'تفعيل')"></button>
                        </div>
                    </div>
                </template>
            </div>
            <p class="text-xs text-slate-500 mt-2" x-show="loadScenario.stats.next_backend_hint">
                <span x-text="t('Next Round Robin pick:', 'التالي:')"></span>
                <strong x-text="loadScenario.stats.next_backend_hint"></strong>
            </p>
            <p class="text-xs text-amber-700 mt-1" x-show="loadScenario.stats.health_mismatch?.length"
                x-text="t('Registry/live mismatch — run Probe nodes to sync.', 'تعارض سجل/حي — اضغط فحص العقد.')"></p>
        </div>
    </template>

    {{-- Mode breakdown --}}
    <template x-if="loadScenario.stats?.mode_breakdown_enriched">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-red-700 mb-1" x-text="t('Before — vertical (single)', 'قبل — عمودي')"></div>
                <div class="text-2xl font-bold" x-text="loadScenario.stats.mode_breakdown_enriched.single?.count ?? 0"></div>
                <div class="text-xs text-slate-500" x-text="(loadScenario.stats.mode_breakdown_enriched.single?.percent ?? 0) + '% of hits'"></div>
                <p class="text-xs mt-1 text-slate-600" x-show="loadScenario.stats.single_server_concentration"
                    x-text="t('All single-mode traffic →', 'كل single →') + ' ' + (loadScenario.stats.single_server_concentration?.server ?? '')"></p>
            </div>
            <div class="rounded-lg border bg-white p-3 text-sm">
                <div class="font-semibold text-emerald-700 mb-1" x-text="t('After — horizontal (Round Robin)', 'بعد — أفقي')"></div>
                <div class="text-2xl font-bold" x-text="loadScenario.stats.mode_breakdown_enriched.round_robin?.count ?? 0"></div>
                <div class="text-xs text-slate-500" x-text="(loadScenario.stats.mode_breakdown_enriched.round_robin?.percent ?? 0) + '% of hits'"></div>
                <p class="text-xs mt-1 font-mono text-slate-600 truncate" x-show="loadScenario.stats.rotation_sequence?.length"
                    x-text="loadScenario.stats.rotation_sequence?.join(' → ')"></p>
            </div>
        </div>
    </template>

    {{-- Request log --}}
    <template x-if="loadScenario.stats?.recent_hits?.length">
        <div>
            <h5 class="font-semibold text-sm mb-2" x-text="t('Request log (last hits)', 'سجل الطلبات')"></h5>
            <div class="overflow-x-auto rounded-lg border bg-white max-h-64 overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2" x-text="t('#', '#')"></th>
                            <th class="px-3 py-2" x-text="t('Mode', 'وضع')"></th>
                            <th class="px-3 py-2" x-text="t('Server', 'خادم')"></th>
                            <th class="px-3 py-2" x-text="t('Port', 'منفذ')"></th>
                            <th class="px-3 py-2" x-text="t('Explanation', 'شرح')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="hit in loadScenario.stats.recent_hits" :key="hit.request_index + '-' + hit.target_server + '-' + hit.recorded_at">
                            <tr class="border-t" :class="{
                                'bg-red-50': hit.distribution_mode === 'single',
                                'bg-emerald-50': hit.distribution_mode === 'round_robin'
                            }">
                                <td class="px-3 py-2 font-mono" x-text="hit.request_index ?? '—'"></td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-1.5 py-0.5 text-xs uppercase"
                                        :class="hit.distribution_mode === 'single' ? 'bg-red-200' : 'bg-emerald-200'"
                                        x-text="hit.distribution_mode"></span>
                                </td>
                                <td class="px-3 py-2 font-medium" x-text="hit.target_server"></td>
                                <td class="px-3 py-2" x-text="hit.target_port ?? '—'"></td>
                                <td class="px-3 py-2 text-xs text-slate-600" x-text="lang === 'ar' ? hit.message_ar : hit.message_en"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </template>

    {{-- Quick multi-port tests --}}
    <div class="rounded-lg border bg-white p-4">
        <h5 class="font-semibold text-sm mb-2" x-text="t('Quick live HTTP tests', 'اختبارات HTTP سريعة')"></h5>
        <p class="text-xs text-slate-600 mb-3"
            x-text="t('Requires .\\scripts\\start-multi-server.ps1 and CACHE_STORE=database.', 'يتطلب start-multi-server.ps1 و CACHE_STORE=database.')">
        </p>
        <div class="flex flex-wrap gap-2 mb-3">
            <button type="button"
                @click="runLoadMultiPortScenario('single', 9)"
                :disabled="loadScenario.loadingMultiPort"
                class="rounded-lg bg-red-600 text-white px-3 py-2 text-sm hover:bg-red-700 disabled:opacity-50"
                x-text="t('9× process-single (port 8000)', '9× process-single')">
            </button>
            <button type="button"
                @click="runLoadMultiPortScenario('balanced', 9)"
                :disabled="loadScenario.loadingMultiPort"
                class="rounded-lg bg-emerald-600 text-white px-3 py-2 text-sm hover:bg-emerald-700 disabled:opacity-50"
                x-text="t('9× process-balanced (RR ports)', '9× process-balanced')">
            </button>
        </div>
        <div class="rounded-lg border-2 border-red-300 bg-red-50 p-3 text-sm text-red-900"
            x-show="loadScenario.multiPortError">
            <p class="font-bold" x-text="t('Multi-port error', 'خطأ multi-port')"></p>
            <p x-text="loadScenario.multiPortError"></p>
        </div>
        <template x-if="loadScenario.multiPortLog?.length">
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100">
                        <tr>
                            <th class="px-3 py-2 text-left" x-text="t('Task #', 'مهمة')"></th>
                            <th class="px-3 py-2 text-left" x-text="t('Mode', 'وضع')"></th>
                            <th class="px-3 py-2 text-left" x-text="t('Port', 'منفذ')"></th>
                            <th class="px-3 py-2 text-left" x-text="t('Handled by', 'عالجها')"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in loadScenario.multiPortLog" :key="row.task_number + '-' + row.mode">
                            <tr class="border-t">
                                <td class="px-3 py-2" x-text="row.task_number"></td>
                                <td class="px-3 py-2" x-text="row.mode"></td>
                                <td class="px-3 py-2 font-mono" x-text="row.target_port ?? '—'"></td>
                                <td class="px-3 py-2 text-xs" x-text="row.handled_by ?? '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</div>
