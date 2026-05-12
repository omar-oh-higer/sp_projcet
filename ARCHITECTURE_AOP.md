# AOP-style architecture (Laravel mapping)

This project uses **Laravel-native mechanisms** instead of a bytecode-weaving AOP framework (e.g. AspectJ). They give the same separation of **core business** vs **cross-cutting concerns**.

## Concepts

| AOP term | Meaning | In this Laravel project |
|----------|---------|-------------------------|
| **Cross-cutting concern** | Behaviour that touches many modules (resilience, limits, logging) | Circuit breaker, HTTP throttling, queued invoice, semaphore in jobs |
| **Join point** | A place where extra behaviour can attach | HTTP request enters route; job `handle()` runs |
| **Advice** | Code that runs at a join point (before / after / around) | Middleware (before/around), job middleware, `recordSuccess()` after success |
| **Aspect** | A module that groups related advice | One middleware class per HTTP concern; jobs for async side effects |
| **Pointcut** | Which join points get the advice | Route middleware groups in `routes/api.php` |

## Implemented split

| Concern | Where it lives | Role |
|---------|----------------|------|
| Circuit breaker **open** check | `app/Http/Middleware/EnsureCircuitBreakerClosed.php` | **Before advice** on locked purchase routes |
| Circuit breaker **success** signal | `OrderController` after successful purchase | **After advice** (must run only on success, so it stays next to the happy path until you promote it to an event listener) |
| Purchase + stock integrity | `StockPurchaseService` | **Core** business logic |
| Invoice queued vs inline | `OrderController` methods + jobs | **Core** for this demo; could later move to a strategy or domain event |
| Daily sales tally (batch vs inline) | `DailySalesTallyController`, `ProcessDailySalesTallyJob` | Task 4: batch processing demo |

## Routes

- `POST /api/buy-without-lock` — no circuit breaker (unsafe demo path).
- `POST /api/buy-with-lock` and `POST /api/buy-with-lock-wait-invoice` — wrapped in `circuit.breaker` middleware alias (see `bootstrap/app.php`).

## Further study (optional)

- **Event + listeners:** fire `OrderPurchaseCompleted` after success; listeners call `recordSuccess()` and dispatch invoice — more “after advice” decoupling.
- **Job middleware:** wrap `ReleaseInvoiceJob` for logging or metrics (around advice on the worker).
- **Full AOP libraries** (e.g. Go! AOP PHP) exist but add complexity; not required for this course.
