# Task 9: Concurrent Stress Test + Report

## Goal

Demonstrate **concurrent stress testing** from Session 8: fire **≥100 simultaneous users** against the API, measure throughput/latency, verify **data integrity invariants**, and submit a structured report.

Lecture reference: `Session_8_Testing,_Tracing,_&_Benchmarking.md`

**Primary safe endpoint:** `POST /api/checkout/acid` (Task 8 ACID checkout).

**Critical:** uses Laravel `Http::pool` (true concurrency), **not** sequential loops like `concurrency:stress` (Task 7).

## Before vs after

| | Before (unsafe under load) | After (submission target) |
|---|---------------------------|---------------------------|
| **Endpoint** | `POST /api/checkout/non-atomic` | `POST /api/checkout/acid` |
| **Concurrency** | 100 simultaneous requests | 100 simultaneous requests |
| **Data integrity** | Orphan payments / stock mismatch | Stock ↔ payments ↔ orders invariant |
| **Report** | `data_integrity_pass: false` | `data_integrity_pass: true`, `system_crashed: false` |

## Report metrics

| Metric | Definition |
|--------|------------|
| **Total Requests** | `--users` (default 100) |
| **Success Requests** | HTTP 200 |
| **Failed Requests** | Connection errors + HTTP 5xx + unexpected 4xx |
| **Rejected (409)** | Insufficient stock — expected under contention, not a crash |
| **Average Response Time** | Mean of `X-Response-Time-Ms` headers (performance middleware) |
| **System Crashed** | `true` if connection error rate > 10% or zero HTTP responses |

## Important files

- `config/stress_testing.php`
- `app/Services/StressTesting/ConcurrentStressRunner.php`
- `app/Services/StressTesting/StressTestIntegrityChecker.php`
- `app/Services/StressTesting/StressTestReportBuilder.php`
- `app/Services/StressTesting/StressTestScenario.php`
- `app/Http/Controllers/StressTestController.php`
- `tests/Feature/StressTestTest.php`

## Prerequisites

```powershell
# Terminal 1
php artisan migrate
php artisan db:seed
php artisan serve
```

## Run stress test

```powershell
# Safe ACID path — submission demo (100 concurrent users)
php artisan stress:concurrent --users=100 --product=1 --scenario=safe --baseUrl=http://127.0.0.1:8000

# Unsafe non-atomic path — compare data integrity failure
php artisan stress:concurrent --users=100 --product=1 --scenario=unsafe --baseUrl=http://127.0.0.1:8000

# Both scenarios in one report
php artisan stress:concurrent --users=100 --product=1 --scenario=both --baseUrl=http://127.0.0.1:8000
```

Reports written to:

- JSON: `storage/app/stress_reports/latest.json`
- Markdown: `storage/docs/STRESS_TEST_REPORT.md`

Read back via API:

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/stress/last-report"
```

## Tests

```powershell
php artisan test --filter=StressTestTest
```

## Sample report (safe scenario)

| Metric | Example value |
|--------|---------------|
| Total Requests | 100 |
| Success Requests | 40 (matches initial stock) |
| Failed Requests | 0 |
| Rejected (409) | 60 |
| Average Response Time | ~25 ms |
| System Crashed | No |
| Data Integrity Pass | Yes |

**What happened:** 100 users hit checkout at once. Only 40 succeeded (available stock). 60 received HTTP 409 (expected). Stock decreased by exactly 40. No orphan payments. Server stayed reachable.

## Observability (Session 8)

After stress run:

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/performance/stats"
```

## Env (optional)

```env
STRESS_TEST_USERS=100
STRESS_TEST_BASE_URL=http://127.0.0.1:8000
STRESS_TEST_QUANTITY=1
STRESS_TEST_TIMEOUT=30
STRESS_TEST_CRASH_THRESHOLD=0.10
```
