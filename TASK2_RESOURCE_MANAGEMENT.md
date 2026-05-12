# Task 2: Resource Management & Capacity Control

## Overview
This task implements three complementary resource management patterns to prevent system overload while maintaining responsiveness:

1. **HTTP Rate Limiting** — Caps concurrent HTTP requests to prevent request storm
2. **Queue Worker Semaphore** — Limits concurrent background job processing to prevent resource exhaustion
3. **Circuit Breaker** — Gracefully degrades when system is unhealthy, protecting downstream resources

---

## Architecture

### Pattern 1: HTTP Rate Limiting (100 req/min per IP)

**Purpose:** Prevent a single client from overwhelming the server with rapid-fire purchase requests.

**Implementation:**
- Defined in `app/Providers/RouteServiceProvider.php`: `purchases` limiter (100 req/min by IP)
- Applied to both `/api/buy-without-lock` and `/api/buy-with-lock` via `throttle:purchases` middleware
- Returns HTTP 429 (Too Many Requests) when limit exceeded

**How It Works:**
```php
RateLimiter::for('purchases', function ($request) {
    return Limit::perMinute(100)->by($request->ip());
});
```
- Tracks requests per IP in Redis/cache
- Resets counter every 60 seconds
- Simple stateless throttling suitable for load balancing

**Testing:**
```bash
# Send 120 requests; expect ~20 to return 429
php artisan load:simulate 1 --requests=120
```

---

### Pattern 2: Queue Worker Semaphore (max 5 concurrent jobs)

**Purpose:** Prevent all background workers from processing jobs simultaneously, which would:
- Consume excessive memory/database connections
- Slow down HTTP response times (resource contention)
- Potentially crash the application

**Implementation:**
- New job: `app/Jobs/ReleaseInvoiceJob.php`
- Wraps `SendInvoiceJob` with semaphore acquire/release
- Uses Laravel Cache for simple single-machine semaphore (not distributed)

**How It Works:**
1. `ReleaseInvoiceJob::dispatch($orderId)` is called after purchase succeeds
2. Worker receives job and attempts `Semaphore::acquire()`
3. If < 5 concurrent workers, acquires slot and runs inner `SendInvoiceJob`
4. On completion, releases slot for next job
5. If >= 5 jobs running, waits up to 30 attempts (30 seconds) then retries job

**Key Code:**
```php
// In ReleaseInvoiceJob::handle()
$currentCount = Cache::get('invoice-processing-semaphore:count', 0);
if ($currentCount < $this->maxConcurrent) {
    Cache::put('invoice-processing-semaphore:count', $currentCount + 1, 3600);
    // ... process SendInvoiceJob ...
    Cache::put('invoice-processing-semaphore:count', $currentCount - 1, 3600);
}
```

**Configuration:**
- Max concurrent: 5 (configurable in `ReleaseInvoiceJob::$maxConcurrent`)
- Retry backoff: [10s, 60s, 300s] after failures
- Max attempts: 3 before job fails

**Testing:**
```bash
# Dispatch 20 jobs to queue
php artisan load:simulate 1 --requests=150

# Process queue with worker
php artisan queue:work

# Monitor logs for semaphore acquisitions/releases
tail -f storage/logs/laravel.log
```

---

### Pattern 3: Circuit Breaker (graceful degradation)

**Purpose:** Detect when system is unhealthy and return 503 (Service Unavailable) instead of failing silently or cascading failures.

**Implementation:**
- New service: `app/Services/CircuitBreakerManager.php`
- Tracks failure rate over 60-second window
- Opens circuit (rejects requests) when > 30% failure rate observed

**States:**
1. **CLOSED** (normal) — Requests proceed normally
2. **OPEN** (degraded) — Requests blocked, return 503 without calling backend
3. **HALF_OPEN** (recovery) — After 5 minutes, try single request; if succeeds, close circuit

**How It Works:**
```php
// In OrderController::buyWithLock()
if ($circuitBreaker->isOpen()) {
    return response()->json(['message' => 'Service temporarily unavailable'], 503);
}

// ... process purchase ...
$circuitBreaker->recordSuccess();  // Reset on success
```

**Failure Tracking:**
- Records timestamp of each failed purchase attempt
- Maintains rolling 60-second window (discards old failures)
- Calculates failure rate as (failures in window / 100)
- Opens circuit if rate exceeds 30%

**Recovery:**
- After 5 minutes with circuit open, transitions to HALF_OPEN
- Next successful request closes circuit
- If fails in HALF_OPEN, reopens and waits another 5 minutes

**Testing:**
```bash
# Check circuit state in code
$cb = new CircuitBreakerManager();
$isOpen = $cb->isOpen();  // Returns bool
$state = $cb->getState(); // Returns 'closed', 'open', or 'half_open'
```

---

## Integration Flow

### Purchase Request Path (with all three patterns):

```
1. Client sends POST /api/buy-with-lock
   ↓
2. [HTTP Rate Limiter] Check if client exceeded 100 req/min → return 429 if over
   ↓
3. [Circuit Breaker] Check if system is open → return 503 if unavailable
   ↓
4. Acquire stock lock (transactional)
   ↓
5. Decrement stock
   ↓
6. Create Order record
   ↓
7. Record success in circuit breaker
   ↓
8. [After Commit] Dispatch ReleaseInvoiceJob to queue
   ↓
9. Return 200 with order_id
```

### Job Processing Path (with semaphore):

```
1. Queue worker receives ReleaseInvoiceJob
   ↓
2. [Semaphore] Wait for slot (max 5 concurrent)
   ↓
3. Execute SendInvoiceJob::handle()
   ↓
4. Log invoice event
   ↓
5. [Release Semaphore] Free slot for next job
```

---

## Files Modified/Created

### Created:
- `app/Jobs/ReleaseInvoiceJob.php` — Semaphore-wrapped invoice job
- `app/Services/CircuitBreakerManager.php` — Circuit breaker state machine
- `tests/Feature/ResourceManagementTest.php` — 11 tests for all three patterns

### Modified:
- `app/Jobs/SendInvoiceJob.php` — Accept `$orderId`, log order details
- `app/Http/Controllers/OrderController.php` — Dispatch `ReleaseInvoiceJob` after purchase, check circuit breaker
- `app/Providers/RouteServiceProvider.php` — Add `purchases` limiter (100 req/min)
- `config/queue.php` — Enable `after_commit: true` for database queue driver
- `routes/api.php` — Apply `throttle:purchases` middleware to purchase endpoints
- `routes/console.php` — Add `load:simulate` command for end-to-end testing

---

## Testing

### Unit Tests
```bash
php artisan test tests/Feature/ResourceManagementTest.php
```

11 tests covering:
- HTTP rate limiting (429 response)
- Semaphore acquire/release
- Semaphore concurrent limit enforcement
- Circuit breaker closed state
- Circuit breaker open on high failure rate
- Async job dispatch on success
- after_commit behavior
- Rate limiter config
- Queue pool settings
- Load simulation command

### Integration Test: Load Simulation
```bash
# Terminal 1: Start server
php artisan serve --host=10.64.106.70

# Terminal 2: Start queue worker
php artisan queue:work

# Terminal 3: Run load test
php artisan load:simulate 1 --requests=150
```

Expected output:
- ~100 successful purchases (100/min rate limit)
- ~50 rate-limited responses (429)
- 20 jobs queued to database queue
- Jobs process with max 5 concurrent (visible in logs)

### Manual Verification

1. **Rate Limiting:**
   ```bash
   for i in {1..120}; do curl -X POST http://10.64.106.70:8000/api/buy-with-lock -d '{"product_id":1,"quantity":1}'; done | grep -o '"status":"[^"]*"' | sort | uniq -c
   ```
   Expected: Mix of 200, 409, and 429 responses

2. **Semaphore:**
   ```bash
   tail -f storage/logs/laravel.log | grep "Semaphore"
   ```
   Expected: "Semaphore acquired", "Semaphore released" logs with concurrent count <= 5

3. **Circuit Breaker:**
   ```bash
   # Manually trigger failures and observe circuit state
   php artisan tinker
   >>> $cb = app(App\Services\CircuitBreakerManager::class);
   >>> $cb->getState();  // Returns 'closed', 'open', or 'half_open'
   ```

---

## Configuration

### Rate Limiter
- **Limit:** 100 requests per minute per IP
- **Location:** `app/Providers/RouteServiceProvider.php`
- **Applied to:** `/api/buy-without-lock`, `/api/buy-with-lock`
- **To adjust:** Change `Limit::perMinute(100)` to desired value

### Semaphore
- **Max Concurrent:** 5 jobs
- **Location:** `app/Jobs/ReleaseInvoiceJob.php` property `$maxConcurrent`
- **Cache Key:** `invoice-processing-semaphore:count`
- **TTL:** 3600 seconds (1 hour)
- **To adjust:** Modify `$maxConcurrent` and cache TTL

### Circuit Breaker
- **Failure Window:** 60 seconds
- **Failure Threshold:** 30% (>30% = open)
- **Open Duration:** 300 seconds (5 minutes)
- **Location:** `app/Services/CircuitBreakerManager.php`
- **To adjust:** Modify class constants

### Queue
- **Driver:** Database
- **After Commit:** Enabled (jobs dispatch after transaction commits)
- **Location:** `config/queue.php`

---

## Logging

All three patterns log events:

**Rate Limiting:** Built-in Laravel logging (check logs for 429 responses)

**Semaphore:**
```
[timestamp] local.INFO: Attempting to acquire semaphore for invoice processing...
[timestamp] local.INFO: Semaphore acquired. Current concurrent jobs: 1/5
[timestamp] local.INFO: Processing invoice for order 123...
[timestamp] local.INFO: Invoice processed for order 123
[timestamp] local.INFO: Semaphore released. Remaining concurrent jobs: 0/5
```

**Circuit Breaker:**
```
[timestamp] local.INFO: Circuit breaker: success in HALF_OPEN state, closing circuit
[timestamp] local.WARNING: Circuit breaker: high failure rate (0.35), opening circuit
[timestamp] local.INFO: Circuit breaker: timeout reached, entering HALF_OPEN state
```

---

## Acceptance Criteria

✅ HTTP rate limiting prevents request storms (100 req/min per IP)
✅ Queue worker semaphore limits concurrent job processing (max 5)
✅ Circuit breaker gracefully degrades on failures (>30% failure rate)
✅ All three patterns work together in purchase flow
✅ Async job dispatch with after_commit prevents orphaned jobs
✅ 11 integration tests verify all patterns
✅ Load simulation command tests all patterns end-to-end
✅ Logging provides visibility into all three patterns

---

## Next Steps (Future Enhancements)

1. **Distributed Semaphore:** Use Redis instead of Cache for multi-server deployments
2. **Circuit Breaker Metrics:** Export to APM (DataDog, New Relic) for dashboards
3. **Adaptive Rate Limiting:** Adjust limits based on system health
4. **Worker Pool Limits:** Use `--max-jobs` and `--max-time` Laravel queue worker options
5. **Dead Letter Queue:** Automatically move failed jobs to separate queue for investigation
6. **Rate Limiter Persistence:** Use persistent store (Redis) instead of in-memory
