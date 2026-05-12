# Task 4: Batch processing (daily sales tally)

## Goal

Tally successful orders for a calendar day (count and total quantity) in a way that scales when there are many rows. This task contrasts:

- **Before:** run the full scan on the **HTTP request thread** (same PHP process as the web request). The implementation uses a **`cursor()`** loop so you can tally very large days without running out of RAM; a naive `get()` would build one giant in-memory `Collection` and hit PHP’s `memory_limit` (e.g. 128MB) on hundreds of thousands of rows. The behaviour you still compare: the **HTTP response waits** until the whole day is processed.
- **After:** `ProcessDailySalesTallyJob::dispatch()` puts work on the queue. Run **`php artisan queue:work`** to see the job run and finish; then **`GET /api/daily-sales-summary`** has the counts. In `.env` use **`QUEUE_CONNECTION=database`** (or `redis`). With **`sync`**, Laravel runs the job inside the web request and **`queue:work` shows nothing** (no row in the `jobs` table).

## Before vs After

| | Before | After |
|---|--------|--------|
| Endpoint | `POST /api/tally-daily-sales-wait` | `POST /api/tally-daily-sales-queued` |
| Thread | Web request (`cursor()` in controller) | **Queue worker** (`chunkById` inside the job) |
| Response | Returns counts when done | Returns right away; counts after worker + `GET /api/daily-sales-summary` |
| `processing_mode` stored | `inline_unbatched` | `queued_batched` |

## Important files

- `app/Http/Controllers/DailySalesTallyController.php`
- `app/Jobs/ProcessDailySalesTallyJob.php`
- `app/Models/DailySalesSummary.php`
- `database/migrations/2026_05_12_120000_create_daily_sales_summaries_table.php`
- `database/seeders/BulkOrdersForTallyDemoSeeder.php`
- `tests/Feature/DailySalesBatchTallyTest.php`

## Big data seed (local)

1. Migrate and base seed (products, etc.):

   `php artisan migrate:fresh --seed`

2. Insert many demo orders (defaults in `config/bulk_orders.php`: count from `BULK_ORDER_SEED_COUNT`, capped by `BULK_ORDER_SEED_MAX`, default max **500,000**):

   `php artisan db:seed --class=BulkOrdersForTallyDemoSeeder`

Every inserted row has `created_at` on the **current calendar day** (when you run the seeder), with a random time that day. Use **today’s date** as `sale_date` in the tally APIs (same day your machine reports, e.g. from `date` / system clock).

3. If you re-seed on another day, older bulk rows stay on their original dates—use `migrate:fresh --seed` or truncate `orders` if you want only “today’s” bulk data.

## Read stored summary

`GET /api/daily-sales-summary?sale_date=YYYY-MM-DD`

## cURL (Postman-style)

Use **`sale_date` = today** (`Y-m-d` in your OS timezone) when you just ran `BulkOrdersForTallyDemoSeeder`—all bulk rows are stamped on that day.

**Before — inline tally (waits until finished):**

```bash
curl.exe -sS -X POST "http://127.0.0.1:8000/api/tally-daily-sales-wait" ^
  -H "Content-Type: application/json" ^
  -H "Accept: application/json" ^
  -d "{\"sale_date\":\"REPLACE_WITH_TODAY_Y-m-d\"}"
```

**After — batched job (run `php artisan queue:work` in another terminal when using `database` / `redis` queue):**

```bash
curl.exe -sS -X POST "http://127.0.0.1:8000/api/tally-daily-sales-queued" ^
  -H "Content-Type: application/json" ^
  -H "Accept: application/json" ^
  -d "{\"sale_date\":\"REPLACE_WITH_TODAY_Y-m-d\"}"
```

**Worker (separate terminal, required for non-sync queues):**

`php artisan queue:work`

**Fetch summary (optional):**

```bash
curl.exe -sS "http://127.0.0.1:8000/api/daily-sales-summary?sale_date=REPLACE_WITH_TODAY_Y-m-d" ^
  -H "Accept: application/json"
```

## Requirements reminder

- `.env`: **`QUEUE_CONNECTION=database`** (or `redis`) so jobs go to the `jobs` table and **`php artisan queue:work`** can pick them up. If you use **`sync`**, jobs never hit the queue and `queue:work` stays idle.
- For large seeds, the **wait** endpoint stays busy until every row for that day is scanned on the web process. Optional: `TALLY_WAIT_MEMORY_LIMIT=512M` in `.env` (see `config/bulk_orders.php`) raises PHP’s limit for that request only.
- A naive `get()` on the same dataset would allocate one huge collection and often crash with “Allowed memory size exhausted”; `cursor()` avoids that while keeping work on the main request.

## One-line summary

The **wait** route scans the day on the web request (`cursor()`). The **queued** route pushes `ProcessDailySalesTallyJob` (batched `chunkById`) to a worker; totals land in `daily_sales_summaries` after the worker runs.
