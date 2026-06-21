<?php

return [

    'products' => [
        1 => ['en' => 'Demo Keyboard', 'ar' => 'لوحة مفاتيح تجريبية'],
        2 => ['en' => 'Demo Mouse', 'ar' => 'فأرة تجريبية'],
        3 => ['en' => 'Demo Monitor', 'ar' => 'شاشة تجريبية'],
    ],

    'tasks' => [
        1 => [
            'slug' => 'database-locks',
            'title_en' => 'Task 1 — Database Locks',
            'title_ar' => 'المهمة 1 — أقفال قاعدة البيانات',
            'problem_en' => 'Two users buying the last item at the same time can both succeed without a row lock — stock becomes wrong (race condition).',
            'problem_ar' => 'مستخدمان يشتريان آخر قطعة في نفس اللحظة قد ينجحان بدون قفل صف — المخزون يصبح خاطئاً (سباق تزامن).',
            'solution_en' => 'Use DB transaction + lockForUpdate() so only one request owns the row while updating.',
            'solution_ar' => 'استخدم معاملة + lockForUpdate() حتى يملك طلب واحد الصف أثناء التحديث.',
            'prerequisites' => [],
            'before' => [
                'method' => 'POST',
                'path' => '/api/buy-without-lock',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/buy-with-lock',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'highlights' => ['stock', 'order_id', 'invoice_mode', 'message'],
            'parallel_demo' => true,
        ],

        2 => [
            'slug' => 'resource-management',
            'title_en' => 'Task 2 — Resource Management',
            'title_ar' => 'المهمة 2 — إدارة الموارد',
            'problem_en' => 'Unlimited concurrent requests can exhaust CPU, DB connections, and memory — the system needs throttling, semaphores, and circuit breakers.',
            'problem_ar' => 'الطلبات المتزامنة غير المحدودة تستنزف المعالج وقاعدة البيانات والذاكرة — نحتاج تحديد معدل وسمافور وقاطع دائرة.',
            'solution_en' => 'Rate limit (3/min purchases), semaphore on tally chunks, circuit breaker on locked routes.',
            'solution_ar' => 'تحديد معدل (3/دقيقة للشراء)، سمافور على دفعات التجميع، قاطع دائرة على مسارات القفل.',
            'prerequisites' => ['cross_task' => [4]],
            'educational_only' => true,
            'highlights' => [],
        ],

        3 => [
            'slug' => 'queue-thread-pool',
            'title_en' => 'Task 3 — Queue / Thread Pool',
            'title_ar' => 'المهمة 3 — الطوابير / مجموعة الخيوط',
            'problem_en' => 'Running slow work (invoice) on the HTTP thread blocks the user and ties up web workers.',
            'problem_ar' => 'تشغيل عمل بطيء (الفاتورة) على خيط HTTP يحجب المستخدم ويستهلك عمال الويب.',
            'solution_en' => 'Dispatch invoice to queue workers — HTTP returns immediately (thread pool model).',
            'solution_ar' => 'أرسل الفاتورة لعمال الطابور — HTTP يعود فوراً (نموذج مجموعة خيوط).',
            'prerequisites' => ['queue' => true],
            'before' => [
                'method' => 'POST',
                'path' => '/api/buy-with-lock-wait-invoice',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/buy-with-lock',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'highlights' => ['invoice_mode', 'stock', 'order_id'],
            'timing_compare' => true,
        ],

        4 => [
            'slug' => 'batch-processing',
            'title_en' => 'Task 4 — Batch Processing',
            'title_ar' => 'المهمة 4 — المعالجة الدفعية',
            'problem_en' => 'Scanning all orders for a day on the HTTP thread blocks the response and risks memory issues at scale.',
            'problem_ar' => 'مسح كل طلبات اليوم على خيط HTTP يحجب الاستجابة وقد يسبب مشاكل ذاكرة على نطاق واسع.',
            'solution_en' => 'Split into chunk jobs via Bus::batch — parallel queue workers merge partial totals.',
            'solution_ar' => 'قسّم إلى وظائف دفعات عبر Bus::batch — عمال الطابور يدمجون المجاميع بالتوازي.',
            'prerequisites' => ['queue' => true, 'multi_queue' => true, 'seed_demo' => true, 'restart_queue' => true],
            'before' => [
                'method' => 'POST',
                'path' => '/api/tally-daily-sales-wait',
                'body' => ['sale_date' => '{saleDate}'],
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/tally-daily-sales-queued',
                'body' => ['sale_date' => '{saleDate}'],
            ],
            'summary_path' => '/api/daily-sales-summary?sale_date={saleDate}',
            'highlights' => ['batch_id', 'expected_chunks', 'processing_mode', 'successful_order_count', 'total_quantity'],
            'poll_summary' => true,
        ],

        5 => [
            'slug' => 'load-distribution',
            'title_en' => 'Task 5 — Load Distribution',
            'title_ar' => 'المهمة 5 — توزيع الحمل',
            'problem_en' => 'Pinning all traffic to one server (vertical scaling) creates a single point of failure under spikes.',
            'problem_ar' => 'توجيه كل الحركة لخادم واحد (توسع عمودي) يخلق نقطة فشل واحدة عند الذروة.',
            'solution_en' => 'Round Robin across healthy backends — horizontal scaling spreads load.',
            'solution_ar' => 'Round Robin عبر الخوادم السليمة — التوسع الأفقي يوزع الحمل.',
            'prerequisites' => [],
            'before' => [
                'method' => 'POST',
                'path' => '/api/load/route-single',
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/load/route-balanced',
            ],
            'highlights' => ['target_server', 'distribution_mode', 'scaling_model', 'node_port'],
            'load_scenario' => true,
            'multi_port' => true,
        ],

        6 => [
            'slug' => 'caching',
            'title_en' => 'Task 6 — Distributed Caching',
            'title_ar' => 'المهمة 6 — التخزين المؤقت الموزع',
            'problem_en' => 'Every product lookup hits the database — slow and expensive under high read traffic.',
            'problem_ar' => 'كل استعلام منتج يضرب قاعدة البيانات — بطيء ومكلف تحت حركة قراءة عالية.',
            'solution_en' => 'Redis Cache-Aside: first call misses (DB + store), second call hits cache.',
            'solution_ar' => 'Redis Cache-Aside: الاستدعاء الأول يفوت (DB + تخزين)، الثاني يصيب الكاش.',
            'prerequisites' => ['redis' => true],
            'before' => [
                'method' => 'GET',
                'path' => '/api/products/{productId}/direct',
            ],
            'after' => [
                'method' => 'GET',
                'path' => '/api/products/{productId}/cached',
            ],
            'highlights' => ['lookup_mode', 'db_queries', 'cache_result'],
            'cache_scenario' => true,
        ],

        7 => [
            'slug' => 'concurrency-control',
            'title_en' => 'Task 7 — Concurrency Control',
            'title_ar' => 'المهمة 7 — التحكم بالتزامن',
            'problem_en' => 'Optimistic locking assumes low conflict — under contention, version conflicts fail purchases.',
            'problem_ar' => 'القفل التفاؤلي يفترض تعارضاً قليلاً — تحت الضغط، تعارض الإصدار يفشل المشتريات.',
            'solution_en' => 'Cluster-wide Redis lock + pessimistic DB transaction for high-contention inventory.',
            'solution_ar' => 'قفل Redis على مستوى العنقود + معاملة DB تشاؤمية للمخزون عالي التعارض.',
            'prerequisites' => ['redis' => true],
            'before' => [
                'method' => 'POST',
                'path' => '/api/buy-optimistic',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/buy-distributed-lock',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'highlights' => ['concurrency_strategy', 'conflict', 'lock_acquired', 'version', 'stock'],
            'concurrency_scenario' => true,
        ],

        8 => [
            'slug' => 'transaction-integrity',
            'title_en' => 'Task 8 — Transaction Integrity (ACID)',
            'title_ar' => 'المهمة 8 — سلامة المعاملات (ACID)',
            'problem_en' => 'Payment, stock, and order in separate commits — a mid-flow failure orphans data.',
            'problem_ar' => 'الدفع والمخزون والطلب في commits منفصلة — فشل منتصف التدفق يترك بيانات يتيمة.',
            'solution_en' => 'Single ACID transaction — all steps succeed or all roll back.',
            'solution_ar' => 'معاملة ACID واحدة — كل الخطوات تنجح أو كلها تتراجع.',
            'prerequisites' => [],
            'before' => [
                'method' => 'POST',
                'path' => '/api/checkout/non-atomic',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'after' => [
                'method' => 'POST',
                'path' => '/api/checkout/acid',
                'body' => ['product_id' => '{productId}', 'quantity' => '{quantity}'],
            ],
            'highlights' => ['status', 'transaction_mode', 'integrity_violation', 'order_id', 'payment_id'],
            'simulate_headers' => true,
            'integrity_scenario' => true,
        ],

        9 => [
            'slug' => 'stress-testing',
            'title_en' => 'Task 9 — Stress Testing',
            'title_ar' => 'المهمة 9 — اختبار الضغط',
            'problem_en' => 'You cannot know system limits until you simulate many concurrent users safely.',
            'problem_ar' => 'لا تعرف حدود النظام حتى تحاكي مستخدمين متزامنين بأمان.',
            'solution_en' => 'Http::pool concurrent checkout stress — unsafe non-atomic vs safe ACID with integrity report.',
            'solution_ar' => 'Http::pool — ضغط concurrent على non-atomic vs ACID مع تقرير سلامة.',
            'prerequisites' => ['serve' => true, 'stress_main_port' => true],
            'report_path' => '/api/stress/stats',
            'highlights' => ['success_requests', 'failed_requests', 'average_response_time_ms', 'data_integrity_pass'],
            'stress_scenario' => true,
        ],

        10 => [
            'slug' => 'benchmarking',
            'title_en' => 'Task 10 — Benchmarking',
            'title_ar' => 'المهمة 10 — قياس الأداء',
            'problem_en' => 'N+1 queries make reports slow — you need measurement before optimizing.',
            'problem_ar' => 'استعلامات N+1 تجعل التقارير بطيئة — تحتاج قياساً قبل التحسين.',
            'solution_en' => 'Eager loading reduces queries and response time — compare slow vs optimized.',
            'solution_ar' => 'التحميل المسبق يقلل الاستعلامات وزمن الاستجابة — قارن البطيء بالمحسّن.',
            'prerequisites' => [],
            'before' => [
                'method' => 'GET',
                'path' => '/api/benchmark/sales-report/slow?product_id={productId}',
            ],
            'after' => [
                'method' => 'GET',
                'path' => '/api/benchmark/sales-report/optimized?product_id={productId}',
            ],
            'highlights' => ['total_duration_ms', 'db_queries', 'bottleneck_span', 'trace_id'],
            'benchmark_comparison' => true,
        ],

        'aop' => [
            'slug' => 'performance-monitoring',
            'title_en' => 'AOP — Performance Monitoring',
            'title_ar' => 'AOP — مراقبة الأداء',
            'problem_en' => 'Without cross-cutting measurement, you cannot see which routes are slow.',
            'problem_ar' => 'بدون قياس عابر، لا ترى أي المسارات بطيئة.',
            'solution_en' => 'Around-advice middleware records every API call — read aggregated stats.',
            'solution_ar' => 'Middleware around-advice يسجل كل استدعاء API — اقرأ الإحصائيات المجمعة.',
            'prerequisites' => [],
            'performance_stats' => true,
            'highlights' => ['duration_ms', 'status_code', 'name'],
        ],
    ],

];
