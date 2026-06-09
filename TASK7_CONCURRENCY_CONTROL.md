# Task 7: Concurrency Control + Distributed Locks

## Goal

Demonstrate **concurrency control** from Session 7: coordinate parallel inventory updates using **Optimistic Locking** (before) vs **Distributed Redis Lock + Pessimistic DB transaction** (after).

Lecture reference: `Session_7_Concurrency_Control_Locks_&_Transactions.md`

## Task 1 vs Task 7

| | Task 1 | Task 7 |
|---|--------|--------|
| **Before** | `POST /api/buy-without-lock` (no lock — race) | `POST /api/buy-optimistic` (version column) |
| **After** | `POST /api/buy-with-lock` (DB `lockForUpdate` only) | `POST /api/buy-distributed-lock` (Redis lock + DB transaction) |
| **Scope** | Single-server DB row lock | **Cluster-wide** mutex via Redis |

## Before vs after

| | Before (Optimistic) | After (Distributed Pessimistic) |
|---|---------------------|--------------------------------|
| **Endpoint** | `POST /api/buy-optimistic` | `POST /api/buy-distributed-lock` |
| **Strategy** | `concurrency_strategy: optimistic` | `concurrency_strategy: distributed_pessimistic` |
| **Under conflict** | `409` + `conflict: true` (version mismatch) | Waits for Redis lock; `503` on lock timeout |
| **Best for** | High-read, low-conflict (lecture) | Critical inventory (lecture) |

## Optimistic flow (before)

1. Read product + `version`
2. Optional `X-DEMO-DELAY-MS` (simulate parallel readers)
3. `UPDATE … WHERE version = ? AND stock >= qty`
4. If `affected === 0` → **version conflict**

## Distributed lock flow (after)

1. `Cache::store(redis)->lock(inventory:product:{id})`
2. `block()` up to `INVENTORY_LOCK_BLOCK` seconds
3. Inside lock: `StockPurchaseService::purchase()` — DB transaction + `lockForUpdate()`
4. Release lock in `finally`

## Important files

- `config/inventory_locking.php`
- `database/migrations/2026_06_08_100000_add_version_to_products_table.php`
- `app/Services/ConcurrencyControl/OptimisticStockPurchaseService.php`
- `app/Services/ConcurrencyControl/InventoryDistributedLock.php`
- `app/Services/ConcurrencyControl/DistributedLockStockPurchaseService.php`
- `app/Http/Controllers/InventoryConcurrencyController.php`
- `tests/Feature/ConcurrencyControlTest.php`

## Redis setup (Docker)

```powershell
docker run -d --name my-redis -p 6379:6379 redis
```

`.env`:

```
INVENTORY_LOCK_STORE=redis
INVENTORY_LOCK_TTL=10
INVENTORY_LOCK_BLOCK=5
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## cURL (Postman)

**Before — optimistic (repeat quickly on low stock):**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/buy-optimistic" -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"product_id\":1,\"quantity\":1}"
```

**With delay header (demo conflict):**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/buy-optimistic" -H "Content-Type: application/json" -H "X-DEMO-DELAY-MS: 500" -d "{\"product_id\":1,\"quantity\":1}"
```

**After — distributed lock:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/buy-distributed-lock" -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"product_id\":1,\"quantity\":1}"
```

**Stats:**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/concurrency/stats" -H "Accept: application/json"
```

**Stress (terminal):**

```powershell
php artisan concurrency:stress --product=1 --requests=20 --strategy=optimistic
php artisan concurrency:stress --product=1 --requests=20 --strategy=distributed
```

## Tests

```powershell
php artisan test --filter=ConcurrencyControlTest
```

Uses `array` lock store — no Redis required in CI.

## One-line summary

**Before:** optimistic versioning fails under concurrent inventory updates. **After:** Redis distributed lock serializes cluster-wide access, then pessimistic DB transaction keeps stock consistent.
