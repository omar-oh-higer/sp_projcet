<!DOCTYPE html>
<html lang="en" x-data="nfrDemo({{ Js::from($tasks) }})" x-init="init()">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NFR Demo Lab — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/nfr-demo.css', 'resources/js/nfr-demo-entry.js'])
</head>
<body class="bg-slate-50 text-slate-900 font-sans antialiased min-h-screen">
    <div class="flex min-h-screen">
        {{-- Sidebar --}}
        <aside class="w-56 shrink-0 bg-slate-900 text-slate-100 flex flex-col">
            <div class="p-4 border-b border-slate-700">
                <h1 class="text-lg font-bold" x-text="t('NFR Demo Lab', 'مختبر المتطلبات غير الوظيفية')"></h1>
                <p class="text-xs text-slate-400 mt-1" x-text="t('Visual API playground', 'ملعب API مرئي')"></p>
            </div>
            <nav class="flex-1 overflow-y-auto p-2 space-y-0.5">
                @foreach($tasks as $id => $task)
                    <button type="button"
                        @click="selectTask(@json($id))"
                        :class="activeTask === @json($id) ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:bg-slate-800'"
                        class="w-full text-left px-3 py-2 rounded-lg text-sm font-medium transition"
                        x-text="lang === 'ar' ? @js($task['title_ar']) : @js($task['title_en'])">
                    </button>
                @endforeach
            </nav>
            <div class="p-3 border-t border-slate-700 text-xs text-slate-500">
                <a href="/" class="hover:text-slate-300">Laravel</a>
                ·
                <a href="/up" target="_blank" class="hover:text-slate-300">Health</a>
            </div>
        </aside>

        {{-- Main --}}
        <main class="flex-1 overflow-y-auto">
            {{-- Top bar --}}
            <header class="sticky top-0 z-10 bg-white border-b border-slate-200 px-6 py-3 flex flex-wrap items-center gap-4">
                <div class="flex rounded-lg border border-slate-200 overflow-hidden text-sm">
                    <button type="button" @click="setLang('en')" :class="lang === 'en' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1.5">EN</button>
                    <button type="button" @click="setLang('ar')" :class="lang === 'ar' ? 'bg-indigo-600 text-white' : 'bg-white'" class="px-3 py-1.5">AR</button>
                </div>
                <label class="text-sm flex items-center gap-2">
                    <span x-text="t('Product', 'منتج')"></span>
                    <select x-model.number="productId" class="rounded border-slate-300 text-sm">
                        @foreach($products as $pid => $names)
                            <option value="{{ $pid }}">{{ $pid }} — {{ $names['en'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm flex items-center gap-2">
                    <span x-text="t('Qty', 'كمية')"></span>
                    <input type="number" x-model.number="quantity" min="1" class="w-16 rounded border-slate-300 text-sm">
                </label>
                <label class="text-sm flex items-center gap-2">
                    <span x-text="t('Sale date', 'تاريخ')"></span>
                    <input type="date" x-model="saleDate" class="rounded border-slate-300 text-sm">
                </label>
            </header>

            <div class="p-6 max-w-5xl">
                @foreach($tasks as $id => $task)
                    <section x-show="activeTask === @json($id)" x-cloak class="space-y-6">
                        <div>
                            <h2 class="text-2xl font-bold" x-text="lang === 'ar' ? @js($task['title_ar']) : @js($task['title_en'])"></h2>
                            <p class="prose-demo mt-2" x-text="lang === 'ar' ? @js($task['problem_ar']) : @js($task['problem_en'])"></p>
                            <p class="prose-demo mt-1 text-indigo-700" x-text="lang === 'ar' ? @js($task['solution_ar'] ?? '') : @js($task['solution_en'] ?? '')"></p>
                        </div>

                        {{-- Prerequisites --}}
                        @if(!empty($task['prerequisites']))
                            <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
                                <strong x-text="t('Prerequisites:', 'متطلبات:')"></strong>
                                <ul class="list-disc ms-5 mt-1">
                                    @if(!empty($task['prerequisites']['queue']))
                                        <li x-text="t('Run: php artisan queue:work', 'شغّل: php artisan queue:work')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['multi_queue']))
                                        <li x-text="t('Run 4 queue workers for parallel chunks (see DAILY_SALES_TALLY_DEMO_WORKER_COUNT)', 'شغّل 4 عمال طابور للدفعات المتوازية')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['restart_queue']))
                                        <li x-text="t('After code changes: Ctrl+C ALL queue:work windows, then start them again', 'بعد تحديث الكود: أوقف كل queue:work ثم شغّلها من جديد')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['multi_server_optional']))
                                        <li x-text="t('Optional: .\\scripts\\start-multi-server.ps1 + CACHE_STORE=database for real HTTP nodes', 'اختياري: multi-server + CACHE_STORE=database')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['serve']))
                                        <li x-text="t('Run: php artisan serve (separate terminal)', 'شغّل: php artisan serve (terminal منفصل)')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['stress_main_port']))
                                        <li x-text="t('Task 9: use main server http://127.0.0.1:8000/demo — demo-run auto-targets the page URL you opened', 'المهمة 9: استخدم http://127.0.0.1:8000/demo — demo-run يوجّه تلقائياً لنفس URL')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['redis']))
                                        <li x-text="t('Redis running + PRODUCT_CACHE_STORE=redis or INVENTORY_LOCK_STORE=redis', 'Redis + إعدادات env المناسبة')"></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['cli']))
                                        <li><code class="bg-amber-100 px-1 rounded">{{ $task['prerequisites']['cli'] }}</code></li>
                                    @endif
                                    @if(!empty($task['prerequisites']['seed_demo']))
                                        <li>
                                            <span x-show="lang === 'en'">Click <strong>Seed today</strong> below — inserts orders for the current date (dynamic)</span>
                                            <span x-show="lang === 'ar'">اضغط <strong>بذر اليوم</strong> أدناه — يُدخل طلبات لتاريخ اليوم الحالي</span>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        @endif

                        @include('demo.partials.task-panel', ['id' => $id, 'task' => $task])
                    </section>
                @endforeach
            </div>
        </main>
    </div>
    <style>[x-cloak]{display:none!important}</style>
</body>
</html>
