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
                en: `Routed to ${body.target_server} (single server, vertical scaling).`,
                ar: `توجيه إلى ${body.target_server} (خادم واحد، توسع عمودي).`,
            },
            after: {
                en: `Round Robin → ${body.target_server} (horizontal scaling).`,
                ar: `Round Robin → ${body.target_server} (توسع أفقي).`,
            },
        },
        6: {
            before: {
                en: `Direct DB lookup: ${body.db_queries ?? '?'} queries. No cache.`,
                ar: `استعلام مباشر: ${body.db_queries ?? '?'} استعلام. بدون كاش.`,
            },
            after: {
                en: body.cache_result === 'hit'
                    ? 'Cache HIT — served from Redis, minimal DB work.'
                    : 'Cache MISS — loaded from DB and stored in Redis.',
                ar: body.cache_result === 'hit'
                    ? 'إصابة كاش — من Redis.'
                    : 'فوت كاش — من DB ثم تخزين في Redis.',
            },
        },
        7: {
            before: {
                en: body.conflict
                    ? 'Optimistic version conflict — another process updated stock first.'
                    : `Optimistic purchase OK. Version: ${body.version ?? '—'}.`,
                ar: body.conflict
                    ? 'تعارض إصدار تفاؤلي — عملية أخرى حدّثت المخزون أولاً.'
                    : `شراء تفاؤلي ناجح. الإصدار: ${body.version ?? '—'}.`,
            },
            after: {
                en: body.lock_acquired
                    ? 'Redis distributed lock acquired, then pessimistic DB purchase.'
                    : 'Could not acquire distributed lock in time (503).',
                ar: body.lock_acquired
                    ? 'قفل Redis موزع، ثم شراء DB تشاؤمي.'
                    : 'تعذر الحصول على القفل في الوقت (503).',
            },
        },
        8: {
            before: {
                en: body.integrity_violation
                    ? 'Non-atomic: partial commit left orphan data (integrity violation).'
                    : `Checkout status: ${body.status}. Separate commits — risk of orphans on failure.`,
                ar: body.integrity_violation
                    ? 'غير ذري: commit جزئي ترك بيانات يتيمة.'
                    : `حالة الدفع: ${body.status}. commits منفصلة — خطر يتامى عند الفشل.`,
            },
            after: {
                en: body.status === 'success'
                    ? 'ACID: all steps committed together.'
                    : 'ACID: failure rolled back everything — no orphan records.',
                ar: body.status === 'success'
                    ? 'ACID: كل الخطوات معاً.'
                    : 'ACID: الفشل تراجع عن كل شيء — لا سجلات يتيمة.',
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

        async runDistributionDemo(taskId, times = 12) {
            const task = tasks[taskId];
            await callApi({ method: 'POST', path: '/api/load/distribution-reset' });
            for (let i = 0; i < times; i++) {
                const ep = i % 2 === 0 ? task.before : task.after;
                await callApi({
                    method: ep.method,
                    path: resolvePath(ep.path, this.vars()),
                });
            }
            await this.fetchStats('/api/load/distribution-stats', 'distribution');
        },

        async setServerHealth(server, healthy) {
            await callApi({
                method: 'POST',
                path: '/api/load/set-server-health',
                body: { server, healthy },
            });
            await this.fetchStats('/api/load/distribution-stats', 'distribution');
        },

        async runCachedTwice(taskId) {
            await this.runSide(taskId, 'after');
            await this.runSide(taskId, 'after');
            await this.fetchStats('/api/cache/stats', 'cache');
        },

        async warmCache() {
            await callApi({ method: 'POST', path: '/api/cache/warm-popular' });
            await this.fetchStats('/api/cache/stats', 'cache');
        },

        async resetCache() {
            await callApi({ method: 'POST', path: '/api/cache/reset' });
            this.stats.cache = null;
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
            await this.fetchStats('/api/stress/last-report', 'stress');
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
            await this.fetchStats('/api/checkout/integrity-stats', 'integrity');
        },

        async loadConcurrencyStats() {
            await this.fetchStats('/api/concurrency/stats', 'concurrency');
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
    };
}

// Alpine registration when loaded via Vite
if (typeof window !== 'undefined') {
    window.nfrDemo = nfrDemo;
    window.NfrDemoCallApi = callApi;
}
