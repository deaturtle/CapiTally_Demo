# CapiTally

Inventory, sales, credit (utang), cash and profit tracking for sari-sari stores.

    capitally/
      backend/    PHP 8.1+ and MySQL JSON API   (document root for the API: backend/public/)
      frontend/   Figma Make UI (React + Vite + Tailwind), now wired to the API
      app/        (you create this) the built frontend, see step 5

## Requirements
- MySQL 8.0.16+ or MariaDB 10.4+ (CHECK constraints and triggers are required)
- PHP 8.1+ with PDO MySQL and mbstring; cURL only for the optional barcode API
- Node.js 20+ and pnpm, only to build the frontend

## What is included
Products and barcode scanning (camera or typed), stock ledger and periodic counts, purchases with supplier payments and
cancellation, sales (cash, GCash, Maya, utang) with returns and cancellations, cash and capital, utang payments, expenses,
reports, dashboard, activity with filters, notes, stock alerts, CSV export, staff accounts with roles, receipts, backups,
and offline selling.

Roles (enforced on the server): Owner does everything. Manager sells, receives and adjusts stock, cancels or returns sales,
and views reports, cash and utang. Cashier only records sales and sees their own sales, with no costs or profit. Inventory
staff receives and adjusts stock. Create staff under Profile, then Staff accounts.

Offline: with no connection you can still scan or search the products saved on the device and record sales. They upload by
themselves when you are back online; each sale carries an id so an upload can never be recorded twice. If a price changed
or stock ran out meanwhile, the sale waits under "Sales saved on this device" for you to decide. Everything else needs a
connection. The browser keeps the app screens after a first online visit (HTTPS or localhost only).

## Setup on your own machine (nothing here has been run for you)
1. Put this folder at `htdocs/capitally` so the API is reachable at `/capitally/backend/public/api/...`.
2. Import `backend/database/schema.sql` (phpMyAdmin Import tab, or `mysql -u root < backend/database/schema.sql`).
   If you imported an earlier version, import only `backend/database/migrations/001_sales_client_uuid.sql` instead.
3. Copy `backend/config/config.local.sample.php` to `config.local.php` and set the database login.
   Recommended: run `backend/database/app_user.sql` (after changing its password) and use that account
   instead of root. It has no DELETE rights except on `login_attempts`.
4. Optional barcode lookup: set `barcode_api.enabled` and `api_key` in `config.local.php`.
   Without it, unknown barcodes simply go to manual product entry.
5. Build the frontend: in `frontend/` run `pnpm install` then `pnpm build`, and copy `frontend/dist/`
   to `capitally/app/`. Open `/capitally/app/`. The first visit shows store setup, then login.
   (Using `pnpm dev` instead works through a proxy to `http://localhost/capitally`.)

## Quick start without XAMPP (for example in an IDE terminal)
Needs PHP 8.1+ (pdo_mysql, mbstring), a running MySQL or MariaDB, and Node with pnpm.
1. Import the database once: `mysql -u root < backend/database/schema.sql` (use the mysql command line, since the triggers need DELIMITER).
2. Copy `backend/config/config.local.sample.php` to `config.local.php` and set the database login.
3. Terminal 1, from this folder: `php -S localhost:8000 -t backend/public`
4. Terminal 2: `cd frontend && pnpm install && pnpm dev`, then open the address Vite prints.
   In dev mode the app talks to the API through a proxy (`/api` to `http://localhost:8000`; set `API_PROXY` to change it).

## Backups
`php backend/bin/backup.php` writes a timestamped dump to `backend/storage/backups/` and keeps the newest 14.
Set `backup.mysqldump_path` in `config.local.php` (XAMPP: `C:\xampp\mysql\bin\mysqldump.exe`) and schedule
it with Windows Task Scheduler or cron. To restore: `mysql -u root capitally < the_backup.sql`.
Do a test restore on a spare database before relying on it.

## Business rules enforced by the database itself
- Stock changes only through `inventory_movements` (a trigger updates quantity and blocks negative stock
  unless an owner override is recorded). Direct edits of `quantity_on_hand` are rejected.
- Inventory, cash and audit ledgers are append-only. Important records cannot be hard-deleted.
- Every ledger row must trace to exactly one origin record.

## API (JSON under /api, all state-changing calls need the X-CSRF-Token header from login or /auth/me)
Auth: GET /auth/status, POST /auth/setup, POST /auth/login, POST /auth/logout, GET /auth/me
Products: GET /products, GET /products/similar, GET /products/{id}, POST /products, PUT /products/{id},
          POST /products/{id}/deactivate|activate
Inventory: GET /inventory/movements, POST /inventory/adjustments
Barcode: GET /barcode/{code}?mode=sale|receiving
Purchases: GET /purchases, GET /purchases/{id}, POST /purchases, POST /purchases/{id}/payments
Sales: GET /sales/{id}, POST /sales, POST /sales/{id}/returns
Purchase cancel: POST /purchases/{id}/cancel
Cash: GET /cash/summary, GET /cash/ledger, PUT /cash/reserve, POST /cash/withdrawals,
      POST /cash/withdrawals/{id}/void, POST /cash/counts
Credit: GET /credits, GET /credits/{id}, POST /credits/{id}/payments, POST /credit-payments/{id}/void
Expenses: GET /expenses, GET /expenses/categories, POST /expenses, POST /expenses/{id}/void
Reports: GET /reports/summary, /reports/sales, /reports/stock, /reports/credits; GET /dashboard; GET /activity
Other: GET /notifications; notes (GET/POST /notes, PUT /notes/{id}, POST /notes/{id}/complete|reopen|archive);
       GET /profile, PUT /profile/store|account, POST /profile/password, PUT /settings

## Good to know
- Cancelling a purchase takes the stock back out and returns any supplier payments to cash, but only while the items are
  unsold. The average cost is not rewound, because it cannot be recovered exactly after sales.
- Offline mode covers selling only. Receiving stock, payments and reports need a connection.
- Sales saved on a device stay there if you log out, so they are not lost; they upload when their cashier logs in again.
- Not built: product variants, multi-store, purchase orders, automated financial statements (later phases in the blueprint).
