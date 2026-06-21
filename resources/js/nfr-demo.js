/* NFR Visual Demo — API client, interpreters, Alpine.js root component */

export async function callApi({ method, path, body = null, headers = {} }) {
    const start = performance.now();
    const opts = {
        method: method.toUpperCase(),
        headers: {
            Accept: 'application/json',
            ...headers,
        },
    };

    if (body !== null && method.toUpperCase() !== 'GET') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    const res = await fetch(path, opts);
    const elapsed = performance.now() - start;
    const responseTimeMs = res.headers.get('X-Response-Time-Ms');
    const contentType = res.headers.get('content-type') || '';

    let parsed;
    if (contentType.includes('application/json')) {
        parsed = await res.json();
    } else {
        parsed = await res.text();
    }

    return {
        status: res.status,
        body: parsed,
        elapsed,
        responseTimeMs: responseTimeMs ? parseFloat(responseTimeMs) : elapsed,
        ok: res.ok,
    };
}

export function resolvePath(path, vars) {
    return path
        .replace('{productId}', String(vars.productId))
        .replace('{quantity}', String(vars.quantity))
        .replace('{saleDate}', vars.saleDate);
}

export function resolveBody(body, vars) {
    if (!body) {
        return null;
    }
    const resolved = {};
    for (const [k, v] of Object.entries(body)) {
        resolved[k] = typeof v === 'string'
            ? v
                .replace('{productId}', String(vars.productId))
                .replace('{quantity}', String(vars.quantity))
                .replace('{saleDate}', vars.saleDate)
            : v;
        if (k === 'product_id' || k === 'quantity') {
            resolved[k] = parseInt(resolved[k], 10);
        }
    }

    return resolved;
}

export function explainResponse(taskId, side, result, lang = 'en') {
    const body = result.body;
    if (typeof body !== 'object' || body === null) {
        return lang === 'ar' ? 'استجابة نصية من الخادم.' : 'Plain text response from server.';
    }

    const rules = {
        1: {
            before: {
                en: result.status === 200
                    ? 'Purchase succeeded without row lock. Under concurrency, two users could oversell.'
                    : 'Request failed — possibly out of stock.',
                ar: result.status === 200
                    ? 'نجح الشراء بدون قفل صف. تحت التزامن، مستخدمان قد يبيعان زيادة.'
                    : 'فشل الطلب — ربما نفد المخزون.',
            },
            after: {
                en: result.status === 200
                    ? 'Safe purchase with lockForUpdate + transaction. Stock stays consistent.'
                    : 'Locked purchase failed safely without corrupting stock.',
                ar: result.status === 200
                    ? 'شراء آمن مع lockForUpdate + معاملة. المخزون يبقى صحيحاً.'
                    : 'فشل الشراء المقفل بأمان دون إفساد المخزون.',
            },
        },
        3: {
            before: {
                en: `Invoice ran inline (invoice_mode: ${body.invoice_mode || 'inline'}). HTTP waited ~${Math.round(result.responseTimeMs)}ms.`,
                ar: `الفاتورة عملت على نفس الخيط. HTTP انتظر ~${Math.round(result.responseTimeMs)}ms.`,
            },
            after: {
                en: `Invoice queued (invoice_mode: ${body.invoice_mode || 'queued'}). HTTP returned in ~${Math.round(result.responseTimeMs)}ms.`,
                ar: `الفاتورة في الطابور. HTTP عاد في ~${Math.round(result.responseTimeMs)}ms.`,
            },
        },
        4: {
            before: {
                en: `Inline tally finished: ${body.successful_order_count ?? '?'} orders, ${body.total_quantity ?? '?'} items. Blocked HTTP thread.`,
                ar: `تجميع مباشر: ${body.successful_order_count ?? '?'} طلب، ${body.total_quantity ?? '?'} قطعة. حجب خيط HTTP.`,
            },
            after: {
                en: `Batch queued: ${body.expected_chunks ?? '?'} chunks, batch_id ${body.batch_id ?? '—'}. Workers process in parallel.`,
                ar: `دفعة في الطابور: ${body.expected_chunks ?? '?'} أجزاء. العمال يعالجون بالتوازي.`,
            },
        },
        5: {
            before: {
                en: body.handled_by
                    ? `Gateway single → ${body.handled_by} (port ${body.target_port ?? '—'}, vertical).`
                    : `Pinned to ${body.target_server} — scaling_model: ${body.scaling_model ?? 'vertical'}. All spike traffic on one server.`,
                ar: body.handled_by
                    ? `Gateway single → ${body.handled_by} (منفذ ${body.target_port ?? '—'}).`
                    : `توجيه إلى ${body.target_server} — توسع عمودي. كل الحركة على خادم واحد.`,
            },
            after: {
                en: body.handled_by
                    ? `Gateway Round Robin → ${body.handled_by} (port ${body.target_port ?? '—'}).`
                    : `Round Robin → ${body.target_server} (${body.strategy ?? 'round_robin'}, ${body.scaling_model ?? 'horizontal'}).`,
                ar: body.handled_by
                    ? `Gateway Round Robin → ${body.handled_by}.`
                    : `Round Robin → ${body.target_server} (توسع أفقي).`,
            },
        },
        6: {
            before: {
                en: `Direct DB lookup — ${body.db_queries ?? '?'} query, lookup_mode: ${body.lookup_mode ?? 'direct_db'}. No Redis.`,
                ar: `استعلام DB مباشر — ${body.db_queries ?? '?'} استعلام. بدون Redis.`,
            },
            after: {
                en: body.cache_result === 'hit'
                    ? `Cache HIT — Redis served product, db_queries: 0, lookup_mode: ${body.lookup_mode ?? 'cache_aside'}.`
                    : body.cache_result === 'bypass'
                        ? `Redis bypass — fell back to DB (${body.db_queries ?? '?'} queries). Check PRODUCT_CACHE_STORE=redis.`
                        : `Cache MISS — loaded from DB (${body.db_queries ?? '?'} query), stored in Redis for next request.`,
                ar: body.cache_result === 'hit'
                    ? 'إصابة كاش — من Redis، db_queries: 0.'
                    : body.cache_result === 'bypass'
                        ? 'تجاوز Redis — رجوع إلى DB. تحقق من Redis.'
                        : `فوت كاش — من DB (${body.db_queries ?? '?'} استعلام) ثم تخزين في Redis.`,
            },
        },
        7: {
            before: {
                en: body.conflict
                    ? `Optimistic version conflict (409) — stock=${body.stock ?? '?'}, version=${body.version ?? '?'}. Another request won the race.`
                    : `Optimistic purchase OK — stock=${body.stock ?? '?'}, version=${body.version ?? '?'}. Under parallel load, expect conflicts.`,
                ar: body.conflict
                    ? `تعارض إصدار (409) — مخزون=${body.stock ?? '?'}.`
                    : `شراء تفاؤلي OK — إصدار ${body.version ?? '?'}.`,
            },
            after: {
                en: body.lock_acquired === false
                    ? 'Distributed lock timeout (503) — Redis busy or unreachable. Check INVENTORY_LOCK_STORE=redis.'
                    : `Distributed lock acquired + purchase OK — stock=${body.stock ?? '?'}, strategy: ${body.concurrency_strategy ?? 'distributed_pessimistic'}.`,
                ar: body.lock_acquired === false
                    ? 'انتهت مهلة قفل Redis (503).'
                    : `قفل Redis + شراء OK — مخزون ${body.stock ?? '?'}.`,
            },
        },
        8: {
            before: {
                en: body.integrity_violation
                    ? `Non-atomic: partial commit at ${body.fail_at ?? '?'} left orphan data (integrity violation).`
                    : `Checkout status: ${body.status}. Separate commits — risk of orphans on failure.`,
                ar: body.integrity_violation
                    ? `غير ذري: commit جزئي عند ${body.fail_at ?? '?'} ترك بيانات يتيمة.`
                    : `حالة الدفع: ${body.status}. commits منفصلة — خطر يتامى عند الفشل.`,
            },
            after: {
                en: body.status === 'success'
                    ? `ACID (${body.transaction_mode ?? 'acid'}): all steps committed together.`
                    : body.rolled_back
                        ? `ACID rollback at ${body.fail_at ?? '?'} — no orphan records (transaction_mode: ${body.transaction_mode ?? 'acid'}).`
                        : 'ACID: failure rolled back everything — no orphan records.',
                ar: body.status === 'success'
                    ? 'ACID: كل الخطوات معاً.'
                    : body.rolled_back
                        ? `ACID rollback — لا سجلات يتيمة (transaction_mode: ${body.transaction_mode ?? 'acid'}).`
                        : 'ACID: الفشل تراجع عن كل شيء — لا سجلات يتيمة.',
            },
        },
        9: {
            before: {
                en: body.data_integrity_pass === false
                    ? `Unsafe stress: ${body.success_requests ?? '?'} OK — integrity FAIL (orphans/oversell possible under non-atomic load).`
                    : `Unsafe concurrent load: ${body.success_requests ?? '?'} successes, avg ${body.average_response_time_ms ?? '?'} ms.`,
                ar: body.data_integrity_pass === false
                    ? `ضغط unsafe: ${body.success_requests ?? '?'} OK — سلامة فشلت (يتامى/oversell).`
                    : `حمل concurrent unsafe: ${body.success_requests ?? '?'} نجاح.`,
            },
            after: {
                en: body.data_integrity_pass
                    ? `Safe ACID stress: ${body.success_requests ?? '?'} OK — integrity PASS, no oversell.`
                    : `Safe ACID stress: integrity ${body.data_integrity_pass ? 'PASS' : 'FAIL'} — ${body.success_requests ?? '?'} successes.`,
                ar: body.data_integrity_pass
                    ? `ضغط ACID: ${body.success_requests ?? '?'} OK — سلامة ناجحة.`
                    : `ضغط ACID: سلامة ${body.data_integrity_pass ? 'OK' : 'FAIL'}.`,
            },
        },
        10: {
            before: {
                en: `Slow report: ${body.total_duration_ms ?? '?'}ms, ${body.db_queries ?? '?'} DB queries. Bottleneck: ${body.bottleneck_span ?? '—'}.`,
                ar: `تقرير بطيء: ${body.total_duration_ms ?? '?'}ms، ${body.db_queries ?? '?'} استعلام.`,
            },
            after: {
                en: `Optimized: ${body.total_duration_ms ?? '?'}ms, ${body.db_queries ?? '?'} queries — fewer round-trips.`,
                ar: `محسّن: ${body.total_duration_ms ?? '?'}ms، ${body.db_queries ?? '?'} استعلام.`,
            },
        },
    };

    const taskRules = rules[taskId]?.[side];
    if (taskRules) {
        return taskRules[lang] || taskRules.en;
    }

    if (result.status === 429) {
        return lang === 'ar' ? 'تحديد معدل — طلبات كثيرة جداً.' : 'Rate limited — too many requests.';
    }
    if (result.status === 503) {
        return lang === 'ar' ? 'الخدمة غير متاحة (قاطع دائرة أو لا خوادم سليمة).' : 'Service unavailable (circuit breaker or no healthy backends).';
    }

    return lang === 'ar' ? `الحالة: ${result.status}` : `Status: ${result.status}`;
}

export function extractHighlights(body, keys) {
    if (typeof body !== 'object' || body === null) {
        return {};
    }
    const out = {};
    for (const key of keys) {
        if (body[key] !== undefined) {
            out[key] = body[key];
        }
    }

    return out;
}

export function barWidth(value, max) {
    if (!max || max <= 0) {
        return '0%';
    }

    return `${Math.min(100, Math.round((value / max) * 100))}%`;
}

function emptyResult() {
    return { status: null, body: null, elapsed: 0, responseTimeMs: 0, ok: false, explain: '', highlights: {} };
}

export function nfrDemo(tasks) {
    return {
        tasks,
        activeTask: 1,
        lang: localStorage.getItem('nfr_demo_lang') || 'en',
        productId: 1,
        quantity: 1,
        saleDate: new Date().toISOString().slice(0, 10),
        simulateFailAt: 'after_payment',
        paymentDeclined: false,
        results: {},
        stats: {},
        loading: {},
        parallelResults: null,
        rateLimitCount: 0,
        pollTimer: null,
        tallyScenario: {
            seedResult: null,
            batchId: null,
            expectedChunks: 0,
            batchStatus: null,
            loadingSeed: false,
            loadingScenario: false,
            loadingRefresh: false,
            refreshError: null,
            lastRefreshedAt: null,
        },
        loadScenario: {
            stats: null,
            loading: false,
            loadingMultiPort: false,
            phase: '',
            multiPortLog: [],
            multiPortError: null,
        },
        cacheScenario: {
            stats: null,
            loading: false,
            phase: '',
        },
        concurrencyScenario: {
            stats: null,
            loading: false,
            phase: '',
        },
        integrityScenario: {
            stats: null,
            loading: false,
            phase: '',
        },
        stressScenario: {
            stats: null,
            loading: false,
            phase: '',
            concurrentUsers: 100,
            usersInitialized: false,
            lastError: null,
        },

        init() {
            const hash = window.location.hash.replace('#task-', '');
            if (hash) {
                if (hash === 'aop') {
                    this.activeTask = 'aop';
                } else {
                    const num = parseInt(hash, 10);
                    this.activeTask = Number.isNaN(num) ? hash : num;
                }
            }
            this.saleDate = new Date().toISOString().slice(0, 10);
            for (const id of Object.keys(tasks)) {
                const key = id === 'aop' ? 'aop' : (Number.isNaN(parseInt(id, 10)) ? id : parseInt(id, 10));
                this.results[key] = { before: emptyResult(), after: emptyResult() };
            }
            if (this.activeTask === 9) {
                this.fetchStressStatus();
            }
        },

        t(en, ar) {
            return this.lang === 'ar' ? ar : en;
        },

        setLang(l) {
            this.lang = l;
            localStorage.setItem('nfr_demo_lang', l);
        },

        selectTask(id) {
            this.activeTask = id;
            window.location.hash = `task-${id}`;
            if (id === 9) {
                this.fetchStressStatus();
            }
        },

        vars() {
            return {
                productId: this.productId,
                quantity: this.quantity,
                saleDate: this.saleDate,
            };
        },

        async runSide(taskId, side) {
            const task = tasks[taskId];
            if (!task || !task[side]) {
                return;
            }
            const key = `${taskId}-${side}`;
            this.loading[key] = true;

            const ep = task[side];
            const path = resolvePath(ep.path, this.vars());
            const body = resolveBody(ep.body, this.vars());
            const headers = {};

            if (task.simulate_headers && taskId === 8) {
                if (this.simulateFailAt) {
                    headers['X-SIMULATE-FAIL-AT'] = this.simulateFailAt;
                }
                if (this.paymentDeclined) {
                    headers['X-SIMULATE-PAYMENT-DECLINED'] = '1';
                }
            }

            try {
                const result = await callApi({ method: ep.method, path, body, headers });
                result.explain = explainResponse(taskId, side, result, this.lang);
                result.highlights = extractHighlights(
                    typeof result.body === 'object' ? result.body : {},
                    task.highlights || [],
                );
                this.results[taskId][side] = result;
            } catch (e) {
                this.results[taskId][side] = {
                    ...emptyResult(),
                    explain: String(e.message),
                };
            } finally {
                this.loading[key] = false;
            }
        },

        async runParallel(taskId, count = 10) {
            const task = tasks[taskId];
            if (!task?.before) {
                return;
            }
            this.loading[`${taskId}-parallel`] = true;
            const ep = task.before;
            const path = resolvePath(ep.path, this.vars());
            const body = resolveBody(ep.body, this.vars());

            const promises = Array.from({ length: count }, () =>
                callApi({ method: ep.method, path, body: { ...body } }),
            );
            const results = await Promise.all(promises);
            const successes = results.filter((r) => r.status === 200).length;
            const conflicts = results.filter((r) => r.status === 409).length;
            this.parallelResults = { total: count, successes, conflicts, results };
            this.loading[`${taskId}-parallel`] = false;
        },

        async runRateLimitDemo() {
            this.rateLimitCount = 0;
            const path = '/api/buy-without-lock';
            const body = resolveBody(
                { product_id: '{productId}', quantity: 1 },
                this.vars(),
            );
            for (let i = 0; i < 6; i++) {
                const r = await callApi({ method: 'POST', path, body });
                if (r.status === 429) {
                    this.rateLimitCount++;
                }
            }
        },

        async fetchStats(endpoint, key) {
            const r = await callApi({ method: 'GET', path: endpoint });
            this.stats[key] = r.body;
        },

        async fetchLoadStatus() {
            const r = await callApi({ method: 'GET', path: '/api/load/distribution-stats' });
            if (r.ok && r.body) {
                this.loadScenario.stats = r.body;
                this.stats.distribution = r.body;
            }

            return r;
        },

        loadRequestDelayMs() {
            return this.loadScenario.stats?.demo_request_delay_ms ?? 300;
        },

        async loadSleep() {
            await new Promise((resolve) => {
                setTimeout(resolve, this.loadRequestDelayMs());
            });
        },

        async resetLoadDemo() {
            await callApi({ method: 'POST', path: '/api/load/distribution-reset' });
            this.loadScenario.phase = '';
            this.loadScenario.multiPortLog = [];
            this.loadScenario.multiPortError = null;
            await this.fetchLoadStatus();
        },

        async runLoadRoutes(path, count) {
            for (let i = 0; i < count; i++) {
                await callApi({ method: 'POST', path });
                await this.fetchLoadStatus();
                await this.loadSleep();
            }
        },

        async runLoadFullScenario(taskId) {
            this.loadScenario.loading = true;
            this.loadScenario.multiPortError = null;

            try {
                await this.resetLoadDemo();

                this.loadScenario.phase = this.t(
                    'Phase 1: 9× vertical (single)',
                    'المرحلة 1: 9× single عمودي',
                );
                await this.runLoadRoutes('/api/load/route-single', 9);

                this.loadScenario.phase = this.t(
                    'Phase 2: 9× horizontal (Round Robin)',
                    'المرحلة 2: 9× balanced أفقي',
                );
                await this.runLoadRoutes('/api/load/route-balanced', 9);

                this.loadScenario.phase = this.t(
                    'Phase 3: server-2 down + 6× balanced',
                    'المرحلة 3: server-2 معطّل + 6× balanced',
                );
                await this.setServerHealth('server-2', false);
                await this.runLoadRoutes('/api/load/route-balanced', 6);

                this.loadScenario.phase = this.t('Done', 'اكتمل');
                await this.runSide(taskId, 'before');
                await this.runSide(taskId, 'after');
            } finally {
                this.loadScenario.loading = false;
            }
        },

        async runLoadMultiPortScenario(mode, count = 9) {
            this.loadScenario.loadingMultiPort = true;
            this.loadScenario.multiPortError = null;
            this.loadScenario.multiPortLog = [];

            const path = mode === 'single'
                ? '/api/load/process-single'
                : '/api/load/process-balanced';

            try {
                await callApi({ method: 'POST', path: '/api/load/distribution-reset' });

                for (let task = 1; task <= count; task++) {
                    const r = await callApi({
                        method: 'POST',
                        path,
                        body: { task_number: task },
                    });

                    if (!r.ok || r.body?.error) {
                        const hint = typeof r.body === 'object' && (r.body?.hint_en || r.body?.hint_ar)
                            ? (this.lang === 'ar' ? r.body.hint_ar : r.body.hint_en)
                            : null;
                        this.loadScenario.multiPortError = hint
                            ?? (typeof r.body === 'object' && r.body?.detail
                                ? r.body.detail
                                : (typeof r.body === 'object' && r.body?.message
                                    ? r.body.message
                                    : this.t(
                                        'Cannot reach worker nodes. Run .\\scripts\\start-multi-server.ps1',
                                        'تعذر الوصول للـ nodes — شغّل start-multi-server.ps1',
                                    )));
                        break;
                    }

                    this.loadScenario.multiPortLog.push({
                        task_number: task,
                        mode,
                        target_port: r.body?.target_port ?? r.body?.worker_response?.node_port,
                        handled_by: r.body?.handled_by ?? r.body?.worker_response?.handled_by,
                    });

                    await this.fetchLoadStatus();
                    await this.loadSleep();
                }
            } finally {
                this.loadScenario.loadingMultiPort = false;
            }
        },

        async setServerHealth(server, healthy) {
            await callApi({
                method: 'POST',
                path: '/api/load/set-server-health',
                body: { server, healthy },
            });
            await this.fetchLoadStatus();
        },

        async runCachedTwice(taskId) {
            await this.runSide(taskId, 'after');
            await this.runSide(taskId, 'after');
            await this.fetchCacheStatus();
        },

        cacheRequestDelayMs() {
            return this.cacheScenario.stats?.demo_request_delay_ms ?? 400;
        },

        async cacheSleep() {
            await new Promise((resolve) => {
                setTimeout(resolve, this.cacheRequestDelayMs());
            });
        },

        async fetchCacheStatus() {
            const params = new URLSearchParams({ product_id: String(this.productId) });
            const r = await callApi({ method: 'GET', path: `/api/cache/stats?${params.toString()}` });
            if (r.ok && r.body) {
                this.cacheScenario.stats = r.body;
                this.stats.cache = r.body;
            }

            return r;
        },

        async resetCacheDemo() {
            await callApi({ method: 'POST', path: '/api/cache/reset' });
            this.cacheScenario.phase = '';
            this.stats.cache = null;
            await this.fetchCacheStatus();
        },

        async runCacheLookup(path) {
            await callApi({ method: 'GET', path });
            await this.fetchCacheStatus();
            await this.cacheSleep();
        },

        async runCacheFullScenario(taskId) {
            this.cacheScenario.loading = true;

            try {
                await this.resetCacheDemo();

                const productId = this.productId;
                const directPath = `/api/products/${productId}/direct`;
                const cachedPath = `/api/products/${productId}/cached`;

                this.cacheScenario.phase = this.t(
                    'Phase 1: 5× direct (always DB)',
                    'المرحلة 1: 5× direct (DB دائماً)',
                );
                for (let i = 0; i < 5; i++) {
                    await this.runCacheLookup(directPath);
                }

                this.cacheScenario.phase = this.t(
                    'Phase 2: 2× cached (miss → hit)',
                    'المرحلة 2: 2× cached (miss → hit)',
                );
                await this.runCacheLookup(cachedPath);
                await this.runCacheLookup(cachedPath);

                this.cacheScenario.phase = this.t(
                    'Phase 3: purchase (invalidates cache)',
                    'المرحلة 3: شراء (إبطال الكاش)',
                );
                await callApi({
                    method: 'POST',
                    path: '/api/buy-with-lock',
                    body: { product_id: productId, quantity: 1 },
                });
                await this.fetchCacheStatus();
                await this.cacheSleep();

                this.cacheScenario.phase = this.t(
                    'Phase 4: 1× cached (miss after invalidation)',
                    'المرحلة 4: cached miss بعد الإبطال',
                );
                await this.runCacheLookup(cachedPath);

                this.cacheScenario.phase = this.t('Done', 'اكتمل');
                await this.runSide(taskId, 'before');
                await this.runSide(taskId, 'after');
            } finally {
                this.cacheScenario.loading = false;
            }
        },

        async warmCache() {
            await callApi({ method: 'POST', path: '/api/cache/warm-popular' });
            await this.fetchCacheStatus();
        },

        async resetCache() {
            await this.resetCacheDemo();
        },

        async pollSummary(taskId) {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
            }
            const path = resolvePath(
                tasks[taskId].summary_path || '/api/daily-sales-summary?sale_date={saleDate}',
                this.vars(),
            );

            const poll = async () => {
                const r = await callApi({ method: 'GET', path });
                this.stats.tallySummary = r;
                if (r.status === 200) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            };

            await poll();
            if (this.stats.tallySummary?.status !== 200) {
                this.pollTimer = setInterval(poll, 2000);
            }
        },

        async seedTallyOrders(clearExisting = true) {
            this.tallyScenario.loadingSeed = true;
            try {
                const r = await callApi({
                    method: 'POST',
                    path: '/api/tally-demo/seed-orders',
                    body: {
                        sale_date: this.saleDate,
                        clear_existing: clearExisting,
                    },
                });
                this.tallyScenario.seedResult = r.body;
                if (r.ok && r.body?.sale_date) {
                    this.saleDate = r.body.sale_date;
                }
            } finally {
                this.tallyScenario.loadingSeed = false;
            }
        },

        async fetchTallyBatchStatus(options = {}) {
            const useLatest = options.latest === true;
            const params = new URLSearchParams({
                sale_date: this.saleDate,
            });
            if (useLatest) {
                params.set('latest', '1');
            } else {
                if (this.tallyScenario.batchId) {
                    params.set('batch_id', this.tallyScenario.batchId);
                }
                if (this.tallyScenario.expectedChunks > 0) {
                    params.set('expected_chunks', String(this.tallyScenario.expectedChunks));
                }
            }
            const r = await callApi({
                method: 'GET',
                path: `/api/tally-demo/batch-status?${params.toString()}`,
            });
            if (r.ok && r.body) {
                this.tallyScenario.batchStatus = r.body;
                if (r.body.batch_id) {
                    this.tallyScenario.batchId = r.body.batch_id;
                }
                if (r.body.expected_chunks != null) {
                    this.tallyScenario.expectedChunks = r.body.expected_chunks;
                }
                if (this.tallyScenario.seedResult && r.body.orders_in_db_for_date != null) {
                    this.tallyScenario.seedResult.orders_for_date = r.body.orders_in_db_for_date;
                }
            }

            return r;
        },

        async refreshTallyStatus() {
            this.tallyScenario.loadingRefresh = true;
            this.tallyScenario.refreshError = null;
            try {
                const r = await this.fetchTallyBatchStatus({
                    latest: !this.tallyScenario.batchId,
                });
                if (r.ok) {
                    this.tallyScenario.lastRefreshedAt = new Date().toLocaleTimeString();
                } else {
                    this.tallyScenario.refreshError = typeof r.body === 'object' && r.body?.message
                        ? r.body.message
                        : (this.lang === 'ar' ? 'فشل التحديث' : 'Refresh failed');
                }
            } catch (e) {
                this.tallyScenario.refreshError = String(e.message);
            } finally {
                this.tallyScenario.loadingRefresh = false;
            }
        },

        async pollTallyBatchStatus() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
            }

            const poll = async () => {
                const r = await this.fetchTallyBatchStatus();
                if (r.ok && r.body?.finalize_ready) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
            };

            await poll();
            if (!this.tallyScenario.batchStatus?.finalize_ready) {
                this.pollTimer = setInterval(poll, 1000);
            }
        },

        async runQueuedTally(taskId) {
            await this.runSide(taskId, 'after');
            const after = this.results[taskId]?.after?.body;
            if (after?.batch_id) {
                this.tallyScenario.batchId = after.batch_id;
                this.tallyScenario.expectedChunks = after.expected_chunks ?? 0;
            }
            await this.pollTallyBatchStatus();
        },

        async runFullTallyScenario(taskId) {
            this.tallyScenario.loadingScenario = true;
            this.tallyScenario.batchStatus = null;
            this.tallyScenario.batchId = null;
            this.tallyScenario.expectedChunks = 0;
            this.tallyScenario.refreshError = null;
            this.tallyScenario.lastRefreshedAt = null;
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
            try {
                this.saleDate = new Date().toISOString().slice(0, 10);
                await this.seedTallyOrders(true);
                await this.runQueuedTally(taskId);
            } finally {
                this.tallyScenario.loadingScenario = false;
            }
        },

        async loadStressReport() {
            await this.fetchStressStatus();
        },

        stressUsersMax() {
            return this.stressScenario.stats?.demo_users_max ?? 100;
        },

        clampStressUsers() {
            const max = this.stressUsersMax();
            const min = 1;
            let n = Number(this.stressScenario.concurrentUsers);

            if (!Number.isFinite(n)) {
                n = this.stressScenario.stats?.demo_users ?? 100;
            }

            this.stressScenario.concurrentUsers = Math.min(max, Math.max(min, Math.round(n)));
        },

        setStressUsersPreset(n) {
            this.stressScenario.concurrentUsers = Math.min(n, this.stressUsersMax());
        },

        stressHasRuns() {
            return (this.stressScenario.stats?.scenario_summary?.run_count ?? 0) > 0;
        },

        stressRequestDelayMs() {
            return this.stressScenario.stats?.demo_request_delay_ms ?? 600;
        },

        async stressSleep() {
            await new Promise((resolve) => {
                setTimeout(resolve, this.stressRequestDelayMs());
            });
        },

        async fetchStressStatus() {
            const params = new URLSearchParams({ product_id: String(this.productId) });
            const r = await callApi({ method: 'GET', path: `/api/stress/stats?${params.toString()}` });
            if (r.ok && r.body) {
                this.stressScenario.stats = r.body;
                this.stats.stress = r.body;

                if (!this.stressScenario.usersInitialized) {
                    this.stressScenario.concurrentUsers = r.body.demo_users ?? 100;
                    this.stressScenario.usersInitialized = true;
                }

                this.clampStressUsers();
            }

            return r;
        },

        async resetStressDemo(resetMetrics = true, clearReport = false) {
            await callApi({
                method: 'POST',
                path: '/api/stress/demo-reset',
                body: {
                    product_id: this.productId,
                    reset_metrics: resetMetrics,
                    clear_report: clearReport,
                },
            });
            this.stressScenario.phase = '';
            await this.fetchStressStatus();
        },

        async runStressDemo(scenario, options = {}) {
            const manageLoading = options.manageLoading !== false;
            const runsBefore = this.stressScenario.stats?.scenario_summary?.run_count ?? 0;

            if (manageLoading) {
                this.stressScenario.loading = true;
            }

            this.stressScenario.lastError = null;
            this.clampStressUsers();

            try {
                const r = await callApi({
                    method: 'POST',
                    path: '/api/stress/demo-run',
                    body: {
                        product_id: this.productId,
                        quantity: this.quantity,
                        scenario,
                        users: this.stressScenario.concurrentUsers,
                        base_url: window.location.origin,
                        write_report: true,
                    },
                });

                if (r.status === 202 || (r.ok && r.body?.status === 'running')) {
                    const completed = await this.waitForStressDemoRun(runsBefore, scenario);
                    if (!completed) {
                        this.stressScenario.lastError = this.t(
                            'Stress run timed out — check php artisan serve is running on this port.',
                            'انتهت مهلة التشغيل — تأكد أن serve يعمل على هذا المنفذ.',
                        );
                    }
                } else if (r.status === 409) {
                    await this.waitForStressDemoRun(runsBefore, scenario);
                } else if (!r.ok) {
                    const body = r.body && typeof r.body === 'object' ? r.body : {};
                    this.stressScenario.lastError = body.output || body.message || `HTTP ${r.status}`;
                    await this.fetchStressStatus();
                } else {
                    await this.fetchStressStatus();
                }
            } finally {
                if (manageLoading) {
                    this.stressScenario.loading = false;
                }
            }
        },

        async waitForStressDemoRun(runsBefore, scenario) {
            const maxWaitMs = 180000;
            const intervalMs = 1500;
            const startedAt = Date.now();
            const expectedMinRuns = scenario === 'both' ? runsBefore + 2 : runsBefore + 1;

            while (Date.now() - startedAt < maxWaitMs) {
                this.stressScenario.phase = this.t(
                    'Stress running in background…',
                    'الضغط يعمل في الخلفية…',
                );
                await this.fetchStressStatus();

                const stats = this.stressScenario.stats;
                const runCount = stats?.scenario_summary?.run_count ?? 0;
                const inProgress = stats?.demo_run_in_progress === true;

                if (!inProgress && runCount >= expectedMinRuns) {
                    this.stressScenario.phase = '';
                    return true;
                }

                if (!inProgress && runCount < expectedMinRuns) {
                    this.stressScenario.lastError = this.t(
                        'Background stress run ended without results — refresh the page and try again. On Windows, demo-run must spawn a detached subprocess.',
                        'انتهى تشغيل الضغط دون نتائج — حدّث الصفحة وحاول مجدداً.',
                    );
                    this.stressScenario.phase = '';
                    return false;
                }

                await new Promise((resolve) => {
                    setTimeout(resolve, intervalMs);
                });
            }

            this.stressScenario.phase = '';
            await this.fetchStressStatus();

            return false;
        },

        async runStressFullScenario(taskId) {
            this.stressScenario.loading = true;
            this.setStressUsersPreset(100);

            try {
                this.stressScenario.phase = this.t(
                    'Phase 0: demo reset (stock + orphans + metrics)',
                    'المرحلة 0: إعادة تعيين',
                );
                await this.resetStressDemo(true, true);
                await this.stressSleep();

                this.stressScenario.phase = this.t(
                    'Phase 1: unsafe concurrent load (non-atomic)',
                    'المرحلة 1: unsafe concurrent',
                );
                await this.runStressDemo('unsafe', { manageLoading: false });
                if (this.stressScenario.lastError) {
                    return;
                }
                await this.stressSleep();

                this.stressScenario.phase = this.t(
                    'Phase 2: cleanup (keep run log)',
                    'المرحلة 2: تنظيف (الإبقاء على السجل)',
                );
                await this.resetStressDemo(false, false);
                await this.stressSleep();

                this.stressScenario.phase = this.t(
                    'Phase 3: safe concurrent load (ACID)',
                    'المرحلة 3: safe ACID concurrent',
                );
                await this.runStressDemo('safe', { manageLoading: false });
                if (this.stressScenario.lastError) {
                    return;
                }
                await this.stressSleep();

                this.stressScenario.phase = this.t('Phase 4: refresh summary', 'المرحلة 4: تحديث');
                await this.fetchStressStatus();

                this.stressScenario.phase = this.t('Done', 'اكتمل');
            } finally {
                this.stressScenario.loading = false;
            }
        },

        async loadBenchmarkComparison() {
            await this.runSide(10, 'before');
            await this.runSide(10, 'after');
            await this.fetchStats('/api/benchmark/comparison', 'benchmark');
            await this.fetchStats('/api/benchmark/traces', 'traces');
        },

        async loadPerformanceStats() {
            await this.fetchStats('/api/performance/stats', 'performance');
        },

        async resetPerformance() {
            await callApi({ method: 'POST', path: '/api/performance/reset' });
            this.stats.performance = null;
        },

        async loadIntegrityStats() {
            await this.fetchIntegrityStatus();
        },

        integrityRequestDelayMs() {
            return this.integrityScenario.stats?.demo_request_delay_ms ?? 400;
        },

        async integritySleep() {
            await new Promise((resolve) => {
                setTimeout(resolve, this.integrityRequestDelayMs());
            });
        },

        async fetchIntegrityStatus() {
            const params = new URLSearchParams({ product_id: String(this.productId) });
            const r = await callApi({ method: 'GET', path: `/api/checkout/integrity-stats?${params.toString()}` });
            if (r.ok && r.body) {
                this.integrityScenario.stats = r.body;
                this.stats.integrity = r.body;
            }

            return r;
        },

        async resetIntegrityDemo(resetMetrics = true) {
            await callApi({
                method: 'POST',
                path: '/api/checkout/demo-reset',
                body: { product_id: this.productId, reset_metrics: resetMetrics },
            });
            this.integrityScenario.phase = '';
            await this.fetchIntegrityStatus();
        },

        async runCheckout(mode) {
            const path = mode === 'acid' ? '/api/checkout/acid' : '/api/checkout/non-atomic';
            const headers = {};

            if (this.simulateFailAt) {
                headers['X-SIMULATE-FAIL-AT'] = this.simulateFailAt;
            }
            if (this.paymentDeclined) {
                headers['X-SIMULATE-PAYMENT-DECLINED'] = '1';
            }

            await callApi({
                method: 'POST',
                path,
                body: { product_id: this.productId, quantity: this.quantity },
                headers,
            });
            await this.fetchIntegrityStatus();
        },

        async runIntegrityFullScenario(taskId) {
            this.integrityScenario.loading = true;
            const savedFailAt = this.simulateFailAt;
            this.simulateFailAt = 'after_payment';
            this.paymentDeclined = false;

            try {
                this.integrityScenario.phase = this.t(
                    'Phase 0: demo reset (clean orphans + restore stock)',
                    'المرحلة 0: إعادة تعيين',
                );
                await this.resetIntegrityDemo(true);
                await this.integritySleep();

                this.integrityScenario.phase = this.t(
                    'Phase 1: non-atomic + fail after payment → orphan',
                    'المرحلة 1: غير ذري → يتيم',
                );
                await this.runCheckout('non-atomic');
                await this.integritySleep();

                this.integrityScenario.phase = this.t(
                    'Phase 2: cleanup orphans (keep log)',
                    'المرحلة 2: تنظيف (الإبقاء على السجل)',
                );
                await this.resetIntegrityDemo(false);
                await this.integritySleep();

                this.integrityScenario.phase = this.t(
                    'Phase 3: ACID + same fail → rollback',
                    'المرحلة 3: ACID → rollback',
                );
                await this.runCheckout('acid');
                await this.integritySleep();

                this.integrityScenario.phase = this.t('Phase 4: refresh summary', 'المرحلة 4: تحديث');
                await this.fetchIntegrityStatus();

                this.integrityScenario.phase = this.t('Done', 'اكتمل');
                await this.runSide(taskId, 'before');
                await this.runSide(taskId, 'after');
            } finally {
                this.simulateFailAt = savedFailAt;
                this.integrityScenario.loading = false;
            }
        },

        async loadConcurrencyStats() {
            await this.fetchConcurrencyStatus();
        },

        concurrencyRequestDelayMs() {
            return this.concurrencyScenario.stats?.demo_request_delay_ms ?? 400;
        },

        async concurrencySleep() {
            await new Promise((resolve) => {
                setTimeout(resolve, this.concurrencyRequestDelayMs());
            });
        },

        async fetchConcurrencyStatus() {
            const params = new URLSearchParams({ product_id: String(this.productId) });
            const r = await callApi({ method: 'GET', path: `/api/concurrency/stats?${params.toString()}` });
            if (r.ok && r.body) {
                this.concurrencyScenario.stats = r.body;
                this.stats.concurrency = r.body;
            }

            return r;
        },

        async resetConcurrencyDemo(resetMetrics = true) {
            await callApi({
                method: 'POST',
                path: '/api/concurrency/demo-reset',
                body: { product_id: this.productId, reset_metrics: resetMetrics },
            });
            this.concurrencyScenario.phase = '';
            await this.fetchConcurrencyStatus();
        },

        async restoreConcurrencyStock() {
            await this.resetConcurrencyDemo(false);
        },

        async runOptimisticConflictBurst(count) {
            const delayMs = this.concurrencyScenario.stats?.demo_optimistic_delay_ms ?? 50;

            await callApi({
                method: 'POST',
                path: '/api/concurrency/demo-stress',
                body: {
                    product_id: this.productId,
                    strategy: 'optimistic',
                    requests: count,
                    parallel_snapshot: true,
                    delay_ms: delayMs,
                },
            });
            await this.fetchConcurrencyStatus();
        },

        async runOptimisticParallelBurst(count) {
            await this.runOptimisticConflictBurst(count);
        },

        async runConcurrencyFullScenario(taskId) {
            this.concurrencyScenario.loading = true;

            try {
                await this.resetConcurrencyDemo();

                const burstCount = this.concurrencyScenario.stats?.demo_burst_count ?? 10;

                this.concurrencyScenario.phase = this.t(
                    `Phase 1: ${burstCount}× optimistic (shared version → conflicts)`,
                    `المرحلة 1: ${burstCount}× optimistic (تعارضات)`,
                );
                await this.runOptimisticConflictBurst(burstCount);
                await this.concurrencySleep();

                this.concurrencyScenario.phase = this.t(
                    'Phase 2: restore stock (keep optimistic log)',
                    'المرحلة 2: إعادة المخزون (الإبقاء على سجل التفاؤلي)',
                );
                await this.restoreConcurrencyStock();

                this.concurrencyScenario.phase = this.t(
                    `Phase 3: ${burstCount}× distributed stress (in-process)`,
                    `المرحلة 3: ${burstCount}× distributed`,
                );
                await callApi({
                    method: 'POST',
                    path: '/api/concurrency/demo-stress',
                    body: {
                        product_id: this.productId,
                        strategy: 'distributed',
                        requests: burstCount,
                    },
                });
                await this.fetchConcurrencyStatus();
                await this.concurrencySleep();

                this.concurrencyScenario.phase = this.t('Done', 'اكتمل');
                await this.runSide(taskId, 'before');
                await this.runSide(taskId, 'after');
            } finally {
                this.concurrencyScenario.loading = false;
            }
        },

        statusClass(status) {
            if (status === null) {
                return 'bg-slate-100 text-slate-500';
            }
            if (status >= 200 && status < 300) {
                return 'bg-emerald-100 text-emerald-800';
            }
            if (status === 409 || status === 429) {
                return 'bg-amber-100 text-amber-800';
            }

            return 'bg-red-100 text-red-800';
        },

        barWidth(value, max) {
            if (!max || max <= 0) {
                return '0%';
            }

            return `${Math.min(100, Math.round((value / max) * 100))}%`;
        },

        maxBar(values) {
            return Math.max(...Object.values(values).map(Number), 1);
        },

        maxServerHits(servers) {
            if (!servers?.length) {
                return 1;
            }

            return Math.max(...servers.map((s) => s.hits ?? 0), 1);
        },
    };
}

// Alpine registration when loaded via Vite
if (typeof window !== 'undefined') {
    window.nfrDemo = nfrDemo;
    window.NfrDemoCallApi = callApi;
}
