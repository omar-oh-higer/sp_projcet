# Task 1: Concurrent Access and Data Integrity

## Goal
Build a safe stock update mechanism so many users can buy the same product at the same time without race condition errors.

This task focuses on non-functional quality:
- Correctness under concurrency
- Data integrity
- Predictable behavior under contention

## What Was Implemented

### Main Components
- Safe purchase endpoint: POST /api/buy-with-lock
- Unsafe demo endpoint: POST /api/buy-without-lock
- Validation for request input (product_id, quantity)
- Transaction + row lock in service layer
- Order recording for success and failure outcomes
- Seed data for deterministic local testing
- Automated feature tests for integrity
- Local concurrency simulation command

### Important Files
- app/Http/Controllers/OrderController.php
- app/Http/Requests/PurchaseRequest.php
- app/Services/StockPurchaseService.php
- app/Models/Product.php
- app/Models/Order.php
- database/migrations/2026_04_21_050115_create_products_table.php
- database/migrations/2026_04_21_060000_create_orders_table.php
- database/seeders/DatabaseSeeder.php
- routes/api.php
- routes/console.php
- tests/Feature/ConcurrentStockIntegrityTest.php

## End-to-End Flow

### 1) Client Sends Purchase Request
Client sends:
- product_id
- quantity

### 2) Validation Layer
PurchaseRequest validates:
- product_id exists
- quantity is integer >= 1

If validation fails, request is rejected before business logic.

### 3) Controller Delegates to Service
OrderController forwards validated input to StockPurchaseService.

### 4) Service Executes Business Logic
StockPurchaseService handles the core logic.

For lock-safe flow:
1. Open DB transaction
2. Lock selected product row with lockForUpdate
3. Re-check stock while lock is held
4. If enough stock:
- decrement stock
- create success order
- commit transaction
5. If not enough stock:
- keep stock unchanged
- create failed order with failure_reason = insufficient_stock
- commit transaction

Result is returned as structured status to controller.

## How It Works WITHOUT Lock (Unsafe Path)
Endpoint: POST /api/buy-without-lock

Behavior:
1. Read product row
2. Check stock
3. Decrement and save

Problem:
Two or more requests can read the same old stock value at the same time.
Each request thinks stock is available and continues.
This causes race condition issues such as lost updates and incorrect success count.

Simple scenario:
- Initial stock = 1
- Request A reads stock = 1
- Request B reads stock = 1
- A writes stock = 0
- B also writes stock = 0

Now both users may receive success even though only one item existed.
That is a data integrity violation.

## How It Works WITH Lock (Safe Path)
Endpoint: POST /api/buy-with-lock

Behavior:
1. Start transaction
2. Fetch product row with FOR UPDATE lock
3. While lock is active, only one transaction can modify that row
4. First request updates stock and commits
5. Next request waits, then reads latest stock after first commit
6. If stock is insufficient, it fails safely (409) and records failed order

Why this is correct:
- No stale stock decision under contention
- No overselling
- Stock never goes below zero
- Success and failure outcomes are auditable through orders table

## How To Test Task 1 (Mentor Demo Script)

## A) Preparation
1. Rebuild DB with deterministic data:

```powershell
php artisan migrate:fresh --seed
```

2. Confirm product seed data exists:

```powershell
php artisan tinker --execute='echo \App\Models\Product::count();'
```

Expected: 3

3. Start server:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

## B) Quick Proof with Automated Tests
Run only Task 1 tests:

```powershell
php artisan test --filter=ConcurrentStockIntegrityTest
```

Expected:
- 3 passed
- Assertions confirm stock integrity and order records

## C) Live Concurrency Demo for Safe Endpoint
In another terminal:

```powershell
php artisan orders:simulate-lock 1 --requests=30 --quantity=3 --baseUrl=http://10.64.106.70:8000
```

What this does:
- Sends 30 requests in parallel to /api/buy-with-lock for product 1
- Each request tries to buy quantity 3
- Prints stock reconciliation (before/after/expected) and integrity check result

Expected style of result:
- Some 200 successes
- Some 409 conflicts
- Other = 0
- Integrity check: PASS

Sample observed run:
- Requests: 30
- Success (200): 13
- Conflict (409): 17
- Other: 0

Interpretation:
- 13 x 3 = 39 units sold
- Initial stock for product 1 is 40
- Remaining stock should be 1
- Further requests fail correctly due to insufficient stock

## D) Show DB Evidence (Audit Trail)
Check stock and order outcomes after simulation:

```powershell
php artisan tinker --execute='echo "Stock=".\App\Models\Product::find(1)->stock.PHP_EOL; echo "Success=".\App\Models\Order::where("product_id",1)->where("status","success")->count().PHP_EOL; echo "Failed=".\App\Models\Order::where("product_id",1)->where("status","failed")->count().PHP_EOL;'
```

Mentor check:
- Stock must never be negative
- Success count must match stock decrement mathematically
- Failed orders should exist when stock is insufficient

## E) Clear Local Race Demo (NO LOCK)
To make race condition visible on localhost, use injected delay in no-lock path:

```powershell
php artisan migrate:fresh --seed
php artisan orders:simulate-nolock 1 --requests=80 --quantity=1 --delayMs=200 --baseUrl=http://10.64.106.70:8000
```

What to watch:
- Success (200) and conflict counts
- Stock before/after
- Expected stock after based on success count
- Integrity check result

Interpretation:
- If `Integrity check: FAIL`, this is a race-condition signature (lost update) in no-lock flow.
- In lock flow, integrity should stay `PASS` for the same style of test.

## Acceptance Checklist for Task 1
- Safe endpoint uses transaction + lockForUpdate
- Unsafe endpoint exists only for learning contrast
- Orders are persisted for success and failure outcomes
- Product stock uses non-negative schema semantics
- Input is validated
- Automated feature tests pass
- Parallel simulation produces only valid outcomes (200 or 409)
- Data reconciliation after simulation is consistent

## Notes for Presentation
- Keep focus on NFR objective: correctness under concurrency, not feature count.
- Demonstrate both paths (unsafe vs safe) to prove why lock is required.
- Always finish by showing DB evidence, not only API responses.
