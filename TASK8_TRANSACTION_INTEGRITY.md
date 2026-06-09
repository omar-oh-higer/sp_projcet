# Task 8: Transaction Integrity (ACID)

## Goal

Demonstrate **ACID composite checkout** from Session 7: **payment + inventory update + order creation** must **all succeed or all fail**, even under concurrent access.

Lecture reference: `Session_7_Concurrency_Control_Locks_&_Transactions.md` (Database Transactions & ACID Properties)

## Task 1 / 7 vs Task 8

| | Task 1 | Task 7 | **Task 8** |
|---|--------|--------|------------|
| **Scope** | Stock + order | Optimistic vs Redis distributed lock | **Payment + stock + order** |
| **Problem** | Race on stock | Cluster mutex / version conflict | **Partial commits** (orphan payment) |
| **Before** | `POST /api/buy-without-lock` | `POST /api/buy-optimistic` | `POST /api/checkout/non-atomic` |
| **After** | `POST /api/buy-with-lock` | `POST /api/buy-distributed-lock` | `POST /api/checkout/acid` |

## ACID mapping

| Property | Non-atomic (before) | ACID (after) |
|----------|---------------------|--------------|
| **Atomicity** | Payment can commit while order fails | Single `DB::transaction` — all or nothing |
| **Consistency** | Stock decremented without order possible | Order always linked to captured payment |
| **Isolation** | No row lock | `lockForUpdate()` inside transaction |
| **Durability** | Partial writes persist | Committed checkout survives; invoice queued **after** commit |

## Before vs after

| | Before (non-atomic) | After (ACID) |
|---|---------------------|--------------|
| **Endpoint** | `POST /api/checkout/non-atomic` | `POST /api/checkout/acid` |
| **Transaction** | Each step auto-commits separately | One `DB::transaction` |
| **On simulated failure** | Orphan payment / inconsistent stock | Full rollback (`rolled_back: true`) |
| **Invoice** | Not dispatched | `ReleaseInvoiceJob::dispatch()->afterCommit()` on success |

## Non-atomic flow (before)

1. Capture payment row (`payments.status = captured`) — **commits**
2. Decrement product stock — **commits**
3. Create order linked to payment

Demo headers:

- `X-SIMULATE-FAIL-AT: after_payment` — payment exists, stock unchanged, no order
- `X-SIMULATE-FAIL-AT: after_stock` — payment + stock changed, no order
- `X-SIMULATE-PAYMENT-DECLINED: 1` — gateway decline before any writes

## ACID flow (after)

1. `BEGIN TRANSACTION`
2. `SELECT product FOR UPDATE`
3. Validate stock; compute `amount_cents = price_cents * quantity`
4. Insert payment (`captured`)
5. Decrement stock + cache invalidation
6. Insert order with `payment_id`; link `payments.order_id`
7. `COMMIT` — then dispatch invoice job after commit

Same demo headers roll back **everything** on the ACID path.

## Important files

- `config/checkout_integrity.php`
- `database/migrations/2026_06_09_100000_add_price_cents_to_products_table.php`
- `database/migrations/2026_06_09_100001_create_payments_table.php`
- `database/migrations/2026_06_09_100002_add_payment_id_to_orders_table.php`
- `app/Models/Payment.php`
- `app/Services/TransactionIntegrity/PaymentGatewaySimulator.php`
- `app/Services/TransactionIntegrity/NonAtomicCheckoutService.php`
- `app/Services/TransactionIntegrity/AcidCheckoutService.php`
- `app/Services/TransactionIntegrity/CheckoutIntegrityMetrics.php`
- `app/Http/Controllers/CheckoutIntegrityController.php`
- `tests/Feature/TransactionIntegrityTest.php`

## API routes

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/api/checkout/non-atomic` | Before — partial commits |
| POST | `/api/checkout/acid` | After — ACID composite |
| GET | `/api/checkout/integrity-stats` | Metrics + orphan payment counts |
| POST | `/api/checkout/integrity-reset` | Reset demo counters |

## Local verification

```powershell
php artisan migrate
php artisan test --filter=TransactionIntegrityTest
```

## Artisan demo

```powershell
php artisan checkout:integrity-demo --product=1 --fail-at=after_payment --mode=non-atomic
php artisan checkout:integrity-demo --product=1 --fail-at=after_payment --mode=acid
```

Non-atomic: orphan payment remains. ACID: zero net DB change after failure.

## cURL examples

**Successful ACID checkout:**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/checkout/acid" -H "Content-Type: application/json" -d "{\"product_id\":1,\"quantity\":1}"
```

**Simulated atomicity failure (non-atomic leaves orphan payment):**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/checkout/non-atomic" -H "Content-Type: application/json" -H "X-SIMULATE-FAIL-AT: after_payment" -d "{\"product_id\":1,\"quantity\":1}"
curl.exe -sS "http://127.0.0.1:8000/api/checkout/integrity-stats"
```

**Same failure on ACID path (full rollback):**

```powershell
curl.exe -sS -X POST "http://127.0.0.1:8000/api/checkout/acid" -H "Content-Type: application/json" -H "X-SIMULATE-FAIL-AT: after_payment" -d "{\"product_id\":1,\"quantity\":1}"
```

## Env (optional)

```env
# CHECKOUT_PAYMENT_PREFIX=pay_
# CHECKOUT_CURRENCY_LABEL=USD
```
