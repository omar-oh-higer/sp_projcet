# AOP-style architecture (Laravel mapping)

This project uses **Laravel-native mechanisms** instead of a bytecode-weaving AOP framework (e.g. AspectJ). They give the same separation of **core business** vs **cross-cutting concerns**.

## Concepts

| AOP term | Meaning | In this Laravel project |
|----------|---------|-------------------------|
| **Cross-cutting concern** | Behaviour that touches many modules (resilience, limits, logging, metrics) | Circuit breaker, HTTP throttling, performance monitoring, queued invoice, semaphore in jobs |
| **Join point** | A place where extra behaviour can attach | HTTP request enters route; job `handle()` runs |
| **Advice** | Code that runs at a join point (before / after / around) | Middleware (before/around), job middleware (around), `recordSuccess()` after success |
| **Aspect** | A module that groups related advice | One middleware class per HTTP concern; job middleware for worker metrics |
| **Pointcut** | Which join points get the advice | API middleware stack in `bootstrap/app.php`; `middleware()` on selected jobs |

## Implemented split

| Concern | Where it lives | Role |
|---------|----------------|------|
| Circuit breaker **open** check | `app/Http/Middleware/EnsureCircuitBreakerClosed.php` | **Before advice** on locked purchase routes |
| Circuit breaker **success** signal | `OrderController` after successful purchase | **After advice** (must run only on success, so it stays next to the happy path until you promote it to an event listener) |
| **Performance monitoring (HTTP)** | `app/Http/Middleware/MeasureRequestPerformance.php` | **Around advice** on all API routes — times request, adds `X-Response-Time-Ms`, persists to `performance_measurements` |
| **Performance monitoring (jobs)** | `app/Jobs/Middleware/MeasureJobPerformance.php` | **Around advice** on `ReleaseInvoiceJob` and `ProcessDailySalesTallyJob` |
| Performance metrics storage / stats | `app/Services/PerformanceMonitoring/PerformanceMonitor.php`, `PerformanceMonitoringController` | Aspect core + read API (not business logic) |
| Purchase + stock integrity | `StockPurchaseService` | **Core** business logic |
| Invoice queued vs inline | `OrderController` methods + jobs | **Core** for this demo; could later move to a strategy or domain event |
| Daily sales tally (batch vs inline) | `DailySalesTallyController`, `ProcessDailySalesTallyJob` | Task 4: batch processing demo |
| Load distribution (single vs Round Robin) | `LoadDistributionController`, `RoundRobinLoadBalancer`, `BackendHealthRegistry` | Task 5: horizontal scaling simulation |

## Routes

- `POST /api/buy-without-lock` — no circuit breaker (unsafe demo path); still measured by performance middleware.
- `POST /api/buy-with-lock` and `POST /api/buy-with-lock-wait-invoice` — wrapped in `circuit.breaker` middleware alias (see `bootstrap/app.php`).
- `GET /api/performance/stats` — aggregated timings recorded by the performance aspect.
- `POST /api/performance/reset` — clear demo measurements.

## Performance monitoring flow (around advice)

```
Request → MeasureRequestPerformance (start timer)
       → [optional circuit.breaker]
       → Controller (core logic only)
       → MeasureRequestPerformance (record + X-Response-Time-Ms header)
       → Response
```

Controllers and jobs **never** call `PerformanceMonitor` directly — only the aspect does.

## cURL (Postman)

**Any API call — read response time header:**

```powershell
curl.exe -i -X POST "http://127.0.0.1:8000/api/buy-with-lock" -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"product_id\":1,\"quantity\":1}"
```

Look for: `X-Response-Time-Ms: ...`

**Performance stats:**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/performance/stats" -H "Accept: application/json"
```

**Reset demo measurements:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/performance/reset" -H "Accept: application/json"
```

## Config

- `config/performance_monitoring.php` — enabled, slow threshold, persist, response header
- Env: `PERFORMANCE_MONITORING_ENABLED`, `PERFORMANCE_SLOW_THRESHOLD_MS`

## Further study (optional)

- **Event + listeners:** fire `OrderPurchaseCompleted` after success; listeners call `recordSuccess()` and dispatch invoice — more “after advice” decoupling.
- **Exclude routes:** skip `/api/performance/*` from self-recording in stats demos.
- **Full AOP libraries** (e.g. Go! AOP PHP) exist but add complexity; not required for this course.
