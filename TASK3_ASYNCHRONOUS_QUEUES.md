# Task 3: Asynchronous Queues

## Goal

Move work that does not require the user to wait (invoice-style processing after a successful purchase) off the main HTTP request thread, and compare that behaviour with a blocking path where the same style of work runs before the response is returned.

This task focuses on non-functional quality:

- Responsiveness of the API (time to first response for the client)
- Decoupling heavy or slow side effects from the critical purchase path
- Using Laravel queues as the mechanism for deferred work

## Before vs After (Scenario)

| Aspect | Before (user waits) | After (user does not wait for invoice) |
|--------|---------------------|----------------------------------------|
| Endpoint | `POST /api/buy-with-lock-wait-invoice` | `POST /api/buy-with-lock` |
| Purchase | Locked transaction via `StockPurchaseService` (same as Task 1) | Same |
| Invoice-style work | Runs on the HTTP thread: optional `X-INVOICE-DELAY-MS` (capped), then `SendInvoiceJob` logic runs inline | `ReleaseInvoiceJob` is dispatched to the queue; HTTP returns after commit + dispatch |
| Response field | `"invoice_mode": "inline"` | `"invoice_mode": "queued"` |
| Queue | Does **not** push `ReleaseInvoiceJob` | Pushes `ReleaseInvoiceJob` (which applies Task 2 semaphore and runs `SendInvoiceJob` in the worker) |

Both paths record the order and decrement stock the same way. The difference is **when** invoice-style work runs relative to the JSON response and **whether** the client’s connection stays open for that work.

## Important Files

- `app/Http/Controllers/OrderController.php` — `buyWithLock`, `buyWithLockWaitForInvoice`, shared `lockedPurchaseOutcome`
- `app/Jobs/ReleaseInvoiceJob.php` — queued wrapper (Task 2 resource control)
- `app/Jobs/SendInvoiceJob.php` — demo “invoice” (logging)
- `routes/api.php` — route registration
- `config/queue.php` — queue drivers (`database`, `sync`, etc.)
- `tests/Feature/AsynchronousQueuesTest.php` — asserts dispatch vs no dispatch using `Queue::fake()`
- `phpunit.xml` — sets `QUEUE_CONNECTION=sync` for the test suite

## Local Demo (Laptop, No Remote Server)

1. Use a non-sync queue driver in `.env`, for example:

   `QUEUE_CONNECTION=database`

2. Ensure the `jobs` table exists (Laravel queue migration if not already applied).

3. Start a worker in a second terminal:

   `php artisan queue:work`

4. Call **after** path — response should return quickly; check logs or worker output for invoice processing:

   `POST /api/buy-with-lock` with JSON body `{ "product_id": 1, "quantity": 1 }`

5. Call **before** path — response should include the time spent on inline invoice work (add `X-INVOICE-DELAY-MS: 2000` header to exaggerate on localhost):

   `POST /api/buy-with-lock-wait-invoice` with the same body and optional delay header.

## PHPUnit Note

The test environment sets `QUEUE_CONNECTION=sync`, which would run dispatched jobs synchronously during the request and would blur the “async” story. **Feature tests use `Queue::fake()`** so we can assert:

- `buy-with-lock` pushes `ReleaseInvoiceJob` exactly once.
- `buy-with-lock-wait-invoice` does **not** push `ReleaseInvoiceJob`, while the purchase still succeeds in the database.

That isolates “queued vs not queued” regardless of the default sync driver in `phpunit.xml`.

## Request Headers (Optional Demo)

- `X-INVOICE-DELAY-MS` — only on `POST /api/buy-with-lock-wait-invoice`; simulates slow invoice generation (milliseconds, capped similarly to `X-DEMO-DELAY-MS` on the without-lock endpoint).

## One-Line Summary

The blocking route keeps the HTTP connection open until inline invoice work finishes; the queued route returns right after a successful purchase and lets a queue worker complete invoice work later—under load, that is the main latency and capacity trade-off, even when the business outcome of the purchase is the same.
