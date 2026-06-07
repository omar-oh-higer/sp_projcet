# Task 6: Distributed Caching (Redis Cache-Aside)

## Goal

Demonstrate **distributed caching** from Session 6: store frequently requested product catalog data in **Redis** so most reads avoid the database.

This task focuses on non-functional quality:

- **Performance** — sub-10ms responses on cache hits vs DB on every request
- **Cache-Aside (Lazy Loading)** — check cache first; on miss load DB and populate cache
- **Invalidation** — manual cache delete when stock changes after purchase; TTL for passive expiry
- **Fail-over** — if Redis is down, fall back to DB (`cache_result: bypass`)

Lecture reference: `Session_6_Caching_Strategies_for_High-Performance_Systems.md`

## Before vs after

| | Before (direct DB) | After (Cache-Aside + Redis) |
|---|-------------------|----------------------------|
| **Lecture idea** | Every product page hits DB (500ms–2000ms under load) | 95% served from memory; DB load drops ~90% |
| **Endpoint** | `GET /api/products/{id}/direct` | `GET /api/products/{id}/cached` |
| **Pattern** | `direct_db` | `cache_aside` |
| **Proof field** | `db_queries: 1` every time | `db_queries: 0` on hit, `1` on miss |

## Cache-Aside flow (after path)

1. **Check cache** — key `product_catalog:product:{id}` in Redis store
2. **Cache hit** — return data; `cache_result: hit`, `db_queries: 0`
3. **Cache miss** — query DB, `put()` with TTL (default 300s), `cache_result: miss`
4. **Next request** — hit

## Invalidation (lecture: manual + TTL)

| Strategy | When | In this project |
|----------|------|-----------------|
| **TTL** | Passive expiry | `PRODUCT_CACHE_TTL=300` |
| **Manual** | Data changed in DB | `ProductCacheInvalidator::forget()` after successful `buy-with-lock` |

## Important files

- `config/product_cache.php`
- `app/Services/ProductCatalog/DirectProductLookup.php`
- `app/Services/ProductCatalog/CachedProductLookup.php`
- `app/Services/ProductCatalog/ProductCacheInvalidator.php`
- `app/Services/ProductCatalog/ProductCatalogMetrics.php`
- `app/Http/Controllers/ProductCatalogController.php`
- `app/Services/StockPurchaseService.php` — invalidates cache on successful purchase
- `tests/Feature/DistributedCachingTest.php`

## Redis setup (Docker)

Product cache uses the **dedicated** `redis` cache store — not `CACHE_STORE=database` (still used for circuit breaker / semaphore).

```powershell
docker run -d --name my-redis -p 6379:6379 redis
```

In `.env`:

```
PRODUCT_CACHE_STORE=redis
PRODUCT_CACHE_TTL=300
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Local demo

1. Migrate + seed: `php artisan migrate --seed`
2. Start Redis (Docker above)
3. Optional warm: `php artisan products:cache-warm`
4. Call before/after routes; read stats

## cURL (Postman)

**Before — DB every time:**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/products/1/direct" -H "Accept: application/json"
```

**After — miss then hit (call twice):**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/products/1/cached" -H "Accept: application/json"
curl.exe -sS "http://127.0.0.1:8000/api/products/1/cached" -H "Accept: application/json"
```

**Cache stats:**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/cache/stats" -H "Accept: application/json"
```

**Warm popular products:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/cache/warm-popular" -H "Accept: application/json"
```

**Reset demo:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/cache/reset" -H "Accept: application/json"
```

**Terminal warm:**

```powershell
php artisan products:cache-warm
```

## Tests

Tests use `array` cache store (no Redis required in CI):

```powershell
php artisan test --filter=DistributedCachingTest
```

## One-line summary

**Before:** every product lookup queries SQLite/MySQL. **After:** Cache-Aside in Redis serves repeat reads from memory; purchase invalidates stale stock; stats show hit rate.
