<div class="compare-card {{ $side === 'before' ? 'compare-card-before' : 'compare-card-after' }}">
    <div class="flex items-center justify-between">
        <h3 class="font-semibold text-sm uppercase tracking-wide">
            @if($side === 'before')
                <span x-text="t('Before (problem)', 'قبل (المشكلة)')"></span>
            @else
                <span x-text="t('After (solution)', 'بعد (الحل)')"></span>
            @endif
        </h3>
        <span class="text-xs font-mono px-2 py-0.5 rounded"
            :class="statusClass(results[{{ json_encode($taskId) }}]['{{ $side }}'].status)"
            x-text="results[{{ json_encode($taskId) }}]['{{ $side }}'].status ?? '—'">
        </span>
    </div>

    <button type="button"
        @click="runSide({{ json_encode($taskId) }}, '{{ $side }}')"
        :disabled="loading[{{ json_encode($taskId) }} + '-{{ $side }}']"
        class="rounded-lg px-4 py-2 text-sm font-medium text-white transition disabled:opacity-50 {{ $side === 'before' ? 'bg-red-500 hover:bg-red-600' : 'bg-emerald-600 hover:bg-emerald-700' }}">
        <span x-show="!loading[{{ json_encode($taskId) }} + '-{{ $side }}']" x-text="t('Run', 'تشغيل')"></span>
        <span x-show="loading[{{ json_encode($taskId) }} + '-{{ $side }}']" x-text="t('Running…', 'جاري…')"></span>
    </button>

    <template x-if="results[{{ json_encode($taskId) }}]['{{ $side }}'].status !== null">
        <div class="space-y-2 text-sm">
            <p class="text-slate-700 font-medium" x-text="results[{{ json_encode($taskId) }}]['{{ $side }}'].explain"></p>
            <p class="text-xs text-slate-500">
                <span x-text="t('Time:', 'الوقت:')"></span>
                <span x-text="Math.round(results[{{ json_encode($taskId) }}]['{{ $side }}'].responseTimeMs) + ' ms'"></span>
            </p>
            <div class="flex flex-wrap gap-1">
                <template x-for="(val, key) in results[{{ json_encode($taskId) }}]['{{ $side }}'].highlights" :key="key">
                    <span class="highlight-badge">
                        <span class="text-slate-500" x-text="key + ':'"></span>
                        <span class="ms-1 font-mono" x-text="typeof val === 'object' ? JSON.stringify(val) : val"></span>
                    </span>
                </template>
            </div>
            <details class="text-xs">
                <summary class="cursor-pointer text-slate-500" x-text="t('Raw JSON', 'JSON خام')"></summary>
                <pre class="mt-1 p-2 bg-slate-900 text-slate-100 rounded overflow-x-auto text-[11px]"
                    x-text="JSON.stringify(results[{{ json_encode($taskId) }}]['{{ $side }}'].body, null, 2)"></pre>
            </details>
        </div>
    </template>
</div>
