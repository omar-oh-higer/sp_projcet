# Task 5: Load Distribution (Round Robin Simulation)

## Goal

Demonstrate **load balancing** and **horizontal scaling** from Session 5: spread incoming requests across a pool of virtual servers instead of sending everything to one machine.

This task focuses on non-functional quality:

- **Availability** — unhealthy servers can be removed from rotation
- **Throughput** — work is shared across a cluster
- **Algorithm choice** — Round Robin when servers and tasks are similar

Lecture reference: `Session_5_Load_Balancing_&_Scaling_Strategies.md`

## Vertical vs horizontal (before / after)

| | Before (vertical) | After (horizontal + Round Robin) |
|---|-------------------|----------------------------------|
| **Lecture idea** | One server takes all traffic (Black Friday 503 risk) | Pool of servers; load balancer distributes |
| **Endpoint** | `POST /api/load/route-single` | `POST /api/load/route-balanced` |
| **Strategy** | `single` — always `server-1` | `round_robin` across healthy backends |
| **Scaling model** | `vertical` | `horizontal` |
| **Stats** | 100% hits on `server-1` | ~equal share on `server-1/2/3` |

## Black Friday narrative (from lecture)

1. **Before:** a flash sale sends a spike to **one server** → CPU/memory saturate → 503 errors. In our demo, every `route-single` call records a hit on `server-1` only.
2. **After:** the site **scales out** to multiple servers; the **load balancer** rotates requests (Round Robin). Stats and `php artisan load:distribute` show traffic spread across the pool.

## Algorithm comparison (lecture)

| Algorithm | Best for | This project |
|-----------|----------|--------------|
| **Round Robin** | Similar hardware, similar task duration | **Implemented** (after path) |
| **Least Connections** | Long-lived or uneven jobs | Documented only |
| **IP Hash** | Sticky sessions / session on server | Documented only — we use **stateless** DB (Tasks 1–4) |
| **Weighted Round Robin** | Different server sizes | Documented only |

### Why Round Robin here

> Requests are distributed sequentially across the list of servers. Once it reaches the end, it starts again from the first server.

Our virtual backends are **homogeneous** and demo requests are **uniform**, so Round Robin is the lecture-recommended choice.

## Health checks (lecture)

Unhealthy backends get **0% traffic** until marked healthy again.

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/load/set-server-health" -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"server\":\"server-2\",\"healthy\":false}"
```

Round Robin then alternates only between remaining healthy servers. Restore with `"healthy": true`.

## Important files

- `config/load_balancing.php`
- `app/Services/LoadBalancing/SingleServerRouter.php`
- `app/Services/LoadBalancing/RoundRobinLoadBalancer.php`
- `app/Services/LoadBalancing/BackendHealthRegistry.php`
- `app/Services/LoadBalancing/LoadDistributionRecorder.php`
- `app/Http/Controllers/LoadDistributionController.php`
- `database/migrations/2026_05_12_140000_create_load_distribution_hits_table.php`
- `tests/Feature/LoadDistributionTest.php`

## Local demo (one `php artisan serve`)

Backends are **virtual IDs** in config — no second terminal required.

1. Migrate: `php artisan migrate`
2. Optional fresh demo: `POST /api/load/distribution-reset`
3. Call before/after routes and read stats

## cURL (Postman)

**Before — single server:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/load/route-single" -H "Content-Type: application/json" -H "Accept: application/json" -d "{}"
```

**After — Round Robin (call several times):**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/load/route-balanced" -H "Content-Type: application/json" -H "Accept: application/json" -d "{}"
```

**Stats:**

```powershell
curl.exe -sS "http://127.0.0.1:8000/api/load/distribution-stats" -H "Accept: application/json"
```

**Mark server unhealthy:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/load/set-server-health" -H "Content-Type: application/json" -H "Accept: application/json" -d "{\"server\":\"server-2\",\"healthy\":false}"
```

**Reset demo data:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/load/distribution-reset" -H "Accept: application/json"
```

**Batch simulation (terminal table):**

```powershell
php artisan load:distribute --requests=30
```

Expect ~10 hits per server when all three are healthy.

## One-line summary

**Before:** one server (`server-1`) absorbs every request like vertical scaling under a spike. **After:** Round Robin spreads requests across a healthy server pool like horizontal scaling — the lecture’s traffic cop — with optional health checks to skip failed nodes.
