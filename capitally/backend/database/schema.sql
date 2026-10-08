-- =====================================================================
-- CapiTally database schema (Checkpoint 2)
-- Target: MySQL 8.0.16+ or MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4.
-- Import with phpMyAdmin (Import tab) or the mysql CLI. Triggers use
-- DELIMITER, so do not paste into a client that lacks DELIMITER support.
--
-- Design rules enforced here, not only in PHP:
--   1. Stock changes only through inventory_movements (trigger).
--   2. Stock cannot go negative without an explicit, attributed override.
--   3. Ledgers (inventory, cash, audit) are append-only.
--   4. Important business records can never be hard-deleted.
--   5. Every ledger row traces to exactly one origin record.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS capitally
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE capitally;

SET NAMES utf8mb4;
SET time_zone = '+08:00';

-- ---------------------------------------------------------------------
-- 1. Stores and users
-- ---------------------------------------------------------------------
CREATE TABLE stores (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(120) NOT NULL,
  owner_name       VARCHAR(120) NOT NULL,
  address          VARCHAR(255) NULL,
  phone            VARCHAR(30)  NULL,
  -- Owner-designated reserve cash, part of the capital formula (blueprint 1.1).
  reserve_cash     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  -- Owner switch for the logged negative-stock override (blueprint 2.3 / Part 5).
  allow_negative_override TINYINT(1) NOT NULL DEFAULT 0,
  -- Per-store sequential receipt/sale number, incremented under row lock.
  next_sale_no     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_stores_reserve CHECK (reserve_cash >= 0)
) ENGINE=InnoDB;

CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  username       VARCHAR(60)  NOT NULL,
  email          VARCHAR(160) NULL,
  password_hash  VARCHAR(255) NOT NULL,   -- password_hash() output only
  full_name      VARCHAR(120) NOT NULL,
  role           ENUM('owner','manager','staff','inventory') NOT NULL DEFAULT 'owner',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_store (store_id),
  CONSTRAINT fk_users_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- Login throttling (brute-force protection). Written by the auth layer.
CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60) NOT NULL,
  ip_address    VARCHAR(45) NOT NULL,
  was_successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_user_time (username, attempted_at),
  KEY idx_login_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Lookups: categories, suppliers
-- ---------------------------------------------------------------------
CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id   INT UNSIGNED NOT NULL,
  name       VARCHAR(80) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_store_name (store_id, name),
  CONSTRAINT fk_categories_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

CREATE TABLE suppliers (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id     INT UNSIGNED NOT NULL,
  name         VARCHAR(120) NOT NULL,
  contact_name VARCHAR(120) NULL,
  phone        VARCHAR(30)  NULL,
  address      VARCHAR(255) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_store_name (store_id, name),
  CONSTRAINT fk_suppliers_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Products
-- ---------------------------------------------------------------------
-- barcode is nullable and deliberately NOT unique: the blueprint requires
-- a warning plus explicit confirmation for duplicate barcodes, not a hard
-- database rejection. The application layer performs that check.
-- cost_price holds the current weighted-average cost (4 dp to limit drift).
-- quantity_on_hand is guarded by triggers: it can only move through
-- inventory_movements. Quantities are DECIMAL because units include kilo.
CREATE TABLE products (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id         INT UNSIGNED NOT NULL,
  barcode          VARCHAR(64) NULL,
  name             VARCHAR(160) NOT NULL,
  category_id      INT UNSIGNED NULL,
  supplier_id      INT UNSIGNED NULL,
  cost_price       DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  selling_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  quantity_on_hand DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  unit             VARCHAR(20) NOT NULL DEFAULT 'pcs',
  reorder_point    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  image_url        VARCHAR(500) NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_products_store_barcode (store_id, barcode),
  KEY idx_products_store_name (store_id, name),
  KEY idx_products_active (store_id, is_active),
  CONSTRAINT fk_products_store    FOREIGN KEY (store_id)    REFERENCES stores(id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_products_creator  FOREIGN KEY (created_by)  REFERENCES users(id),
  CONSTRAINT chk_products_cost    CHECK (cost_price >= 0),
  CONSTRAINT chk_products_price   CHECK (selling_price >= 0),
  CONSTRAINT chk_products_reorder CHECK (reorder_point >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Origin records for inventory movements
-- ---------------------------------------------------------------------
-- Manual adjustments, opening stock and periodic counts all need a real
-- origin record so every ledger row traces to something.
CREATE TABLE stock_adjustments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id        INT UNSIGNED NOT NULL,
  adjustment_type ENUM('manual','periodic_count','opening_stock') NOT NULL,
  note            VARCHAR(500) NULL,
  user_id         INT UNSIGNED NOT NULL,
  approved_by     INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stock_adj_store_date (store_id, created_at),
  CONSTRAINT fk_stock_adj_store    FOREIGN KEY (store_id)    REFERENCES stores(id),
  CONSTRAINT fk_stock_adj_user     FOREIGN KEY (user_id)     REFERENCES users(id),
  CONSTRAINT fk_stock_adj_approver FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustment_items (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  adjustment_id INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  system_qty    DECIMAL(12,3) NOT NULL,   -- ledger quantity when the count was taken
  counted_qty   DECIMAL(12,3) NOT NULL,   -- physically counted quantity
  variance      DECIMAL(12,3) NOT NULL,   -- counted_qty - system_qty
  reason_code   ENUM('opening_stock','damage','theft','miscount','expired','other') NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sai_adjustment (adjustment_id),
  KEY idx_sai_product (product_id),
  CONSTRAINT fk_sai_adjustment FOREIGN KEY (adjustment_id) REFERENCES stock_adjustments(id),
  CONSTRAINT fk_sai_product    FOREIGN KEY (product_id)    REFERENCES products(id),
  CONSTRAINT chk_sai_counted   CHECK (counted_qty >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. Purchases (stock-in), supplier payments
-- ---------------------------------------------------------------------
CREATE TABLE purchases (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id      INT UNSIGNED NOT NULL,
  supplier_id   INT UNSIGNED NULL,
  purchase_date DATE NOT NULL,
  reference_no  VARCHAR(60) NULL,
  total_cost    DECIMAL(12,2) NOT NULL,
  status        ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
  notes         VARCHAR(500) NULL,
  user_id       INT UNSIGNED NOT NULL,
  cancelled_at  DATETIME NULL,
  cancelled_by  INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchases_store_date (store_id, purchase_date),
  CONSTRAINT fk_purchases_store    FOREIGN KEY (store_id)     REFERENCES stores(id),
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id)  REFERENCES suppliers(id),
  CONSTRAINT fk_purchases_user     FOREIGN KEY (user_id)      REFERENCES users(id),
  CONSTRAINT fk_purchases_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT chk_purchases_total   CHECK (total_cost >= 0)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_id INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  quantity    DECIMAL(12,3) NOT NULL,
  unit_cost   DECIMAL(12,4) NOT NULL,
  line_total  DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pi_purchase (purchase_id),
  KEY idx_pi_product (product_id),
  CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  CONSTRAINT fk_pi_product  FOREIGN KEY (product_id)  REFERENCES products(id),
  CONSTRAINT chk_pi_qty  CHECK (quantity > 0),
  CONSTRAINT chk_pi_cost CHECK (unit_cost >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. Sales
-- ---------------------------------------------------------------------
CREATE TABLE sales (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  sale_no        INT UNSIGNED NOT NULL,            -- per-store sequence
  sale_date      DATETIME NOT NULL,
  total_amount   DECIMAL(12,2) NOT NULL,           -- revenue
  total_cost     DECIMAL(12,2) NOT NULL,           -- sum of snapshotted line costs (COGS)
  payment_method ENUM('cash','gcash','maya','credit') NOT NULL,
  customer_name  VARCHAR(120) NULL,
  customer_phone VARCHAR(30)  NULL,
  status         ENUM('completed','partially_returned','returned','cancelled') NOT NULL DEFAULT 'completed',
  notes          VARCHAR(500) NULL,
  -- Client-generated id so a retried or offline-synced sale is recorded only once.
  client_uuid    CHAR(36) NULL,
  user_id        INT UNSIGNED NOT NULL,
  cancelled_at   DATETIME NULL,
  cancelled_by   INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_store_no (store_id, sale_no),
  UNIQUE KEY uq_sales_client (store_id, client_uuid),
  KEY idx_sales_store_date (store_id, sale_date),
  KEY idx_sales_payment (store_id, payment_method),
  CONSTRAINT fk_sales_store     FOREIGN KEY (store_id)     REFERENCES stores(id),
  CONSTRAINT fk_sales_user      FOREIGN KEY (user_id)      REFERENCES users(id),
  CONSTRAINT fk_sales_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT chk_sales_total    CHECK (total_amount >= 0),
  CONSTRAINT chk_sales_cost     CHECK (total_cost >= 0),
  -- A credit sale must name the customer.
  CONSTRAINT chk_sales_credit_customer
    CHECK (payment_method <> 'credit' OR (customer_name IS NOT NULL AND customer_name <> ''))
) ENGINE=InnoDB;

-- product_name and unit_price/unit_cost are SNAPSHOTS taken at sale time.
-- Historical profit is always computed from these, never from products.
CREATE TABLE sale_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id           INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  barcode           VARCHAR(64) NULL,
  quantity          DECIMAL(12,3) NOT NULL,
  unit_price        DECIMAL(12,2) NOT NULL,
  unit_cost         DECIMAL(12,4) NOT NULL,
  line_total        DECIMAL(12,2) NOT NULL,
  line_cost         DECIMAL(12,2) NOT NULL,
  returned_quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (id),
  KEY idx_si_sale (sale_id),
  KEY idx_si_product (product_id),
  CONSTRAINT fk_si_sale    FOREIGN KEY (sale_id)    REFERENCES sales(id),
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_si_qty      CHECK (quantity > 0),
  CONSTRAINT chk_si_price    CHECK (unit_price >= 0),
  CONSTRAINT chk_si_cost     CHECK (unit_cost >= 0),
  CONSTRAINT chk_si_returned CHECK (returned_quantity >= 0 AND returned_quantity <= quantity)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. Returns and cancellations (linked reversing records)
-- ---------------------------------------------------------------------
-- A full cancellation is a return of every item with return_type='cancellation'.
-- total_value = credit_offset_amount + refund_amount:
--   * credit_offset_amount reduces what the customer still owes on a credit sale
--   * refund_amount is money actually paid back (hits the cash ledger)
CREATE TABLE sale_returns (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id             INT UNSIGNED NOT NULL,
  sale_id              INT UNSIGNED NOT NULL,
  return_type          ENUM('return','cancellation') NOT NULL,
  reason               VARCHAR(500) NOT NULL,
  total_value          DECIMAL(12,2) NOT NULL,
  credit_offset_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_method        ENUM('cash','gcash','maya') NULL,
  goods_restocked      TINYINT(1) NOT NULL DEFAULT 1,  -- 0 = goods damaged, not returned to shelf
  user_id              INT UNSIGNED NOT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sr_sale (sale_id),
  KEY idx_sr_store_date (store_id, created_at),
  CONSTRAINT fk_sr_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_sr_sale  FOREIGN KEY (sale_id)  REFERENCES sales(id),
  CONSTRAINT fk_sr_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT chk_sr_total   CHECK (total_value >= 0),
  CONSTRAINT chk_sr_split   CHECK (credit_offset_amount >= 0 AND refund_amount >= 0
                                   AND credit_offset_amount + refund_amount = total_value),
  CONSTRAINT chk_sr_method  CHECK (refund_amount = 0 OR refund_method IS NOT NULL)
) ENGINE=InnoDB;

CREATE TABLE sale_return_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_return_id INT UNSIGNED NOT NULL,
  sale_item_id   INT UNSIGNED NOT NULL,
  product_id     INT UNSIGNED NOT NULL,
  quantity       DECIMAL(12,3) NOT NULL,
  unit_price     DECIMAL(12,2) NOT NULL,   -- copied from the original sale item
  unit_cost      DECIMAL(12,4) NOT NULL,   -- copied from the original sale item
  line_value     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sri_return (sale_return_id),
  CONSTRAINT fk_sri_return  FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id),
  CONSTRAINT fk_sri_item    FOREIGN KEY (sale_item_id)   REFERENCES sale_items(id),
  CONSTRAINT fk_sri_product FOREIGN KEY (product_id)     REFERENCES products(id),
  CONSTRAINT chk_sri_qty    CHECK (quantity > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. Inventory movement ledger (append-only, trigger-guarded)
-- ---------------------------------------------------------------------
CREATE TABLE inventory_movements (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id           INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NOT NULL,
  movement_type      ENUM('opening_stock','purchase','purchase_cancel','sale','sale_return',
                          'sale_cancel','adjustment','spoilage','loss') NOT NULL,
  reason_code        ENUM('opening_stock','damage','theft','miscount','expired','other') NULL,
  quantity_change    DECIMAL(12,3) NOT NULL,        -- positive in, negative out
  quantity_after     DECIMAL(12,3) NOT NULL DEFAULT 0.000,  -- set by trigger
  unit_cost          DECIMAL(12,4) NULL,            -- cost snapshot for valuation
  -- Exactly one origin must be set (CHECK below).
  sale_id            INT UNSIGNED NULL,
  purchase_id        INT UNSIGNED NULL,
  adjustment_id      INT UNSIGNED NULL,
  sale_return_id     INT UNSIGNED NULL,
  -- Logged negative-stock override.
  is_negative_override TINYINT(1) NOT NULL DEFAULT 0,
  override_by        INT UNSIGNED NULL,
  user_id            INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_im_product_time (product_id, created_at),
  KEY idx_im_store_time (store_id, created_at),
  KEY idx_im_sale (sale_id),
  KEY idx_im_purchase (purchase_id),
  KEY idx_im_adjustment (adjustment_id),
  KEY idx_im_return (sale_return_id),
  CONSTRAINT fk_im_store      FOREIGN KEY (store_id)       REFERENCES stores(id),
  CONSTRAINT fk_im_product    FOREIGN KEY (product_id)     REFERENCES products(id),
  CONSTRAINT fk_im_sale       FOREIGN KEY (sale_id)        REFERENCES sales(id),
  CONSTRAINT fk_im_purchase   FOREIGN KEY (purchase_id)    REFERENCES purchases(id),
  CONSTRAINT fk_im_adjustment FOREIGN KEY (adjustment_id)  REFERENCES stock_adjustments(id),
  CONSTRAINT fk_im_return     FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id),
  CONSTRAINT fk_im_override   FOREIGN KEY (override_by)    REFERENCES users(id),
  CONSTRAINT fk_im_user       FOREIGN KEY (user_id)        REFERENCES users(id),
  CONSTRAINT chk_im_nonzero   CHECK (quantity_change <> 0),
  CONSTRAINT chk_im_one_origin CHECK (
    (sale_id IS NOT NULL) + (purchase_id IS NOT NULL)
    + (adjustment_id IS NOT NULL) + (sale_return_id IS NOT NULL) = 1),
  -- Adjustments, spoilage and loss must carry a reason (blueprint 2.3).
  CONSTRAINT chk_im_reason CHECK (
    movement_type NOT IN ('adjustment','spoilage','loss','opening_stock') OR reason_code IS NOT NULL),
  CONSTRAINT chk_im_override_user CHECK (is_negative_override = 0 OR override_by IS NOT NULL)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. Credit (utang)
-- ---------------------------------------------------------------------
-- One credit per credit sale, linked to the sale and therefore its items.
-- Balance is derived (see v_credit_balances), not stored.
CREATE TABLE credits (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  sale_id        INT UNSIGNED NOT NULL,
  customer_name  VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(30)  NULL,
  original_amount DECIMAL(12,2) NOT NULL,
  due_date       DATE NULL,
  status         ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_credits_sale (sale_id),
  KEY idx_credits_store_status (store_id, status),
  KEY idx_credits_customer (store_id, customer_phone),
  CONSTRAINT fk_credits_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_credits_sale  FOREIGN KEY (sale_id)  REFERENCES sales(id),
  CONSTRAINT chk_credits_amount CHECK (original_amount > 0)
) ENGINE=InnoDB;

CREATE TABLE credit_payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  credit_id      INT UNSIGNED NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash','gcash','maya') NOT NULL,
  paid_at        DATETIME NOT NULL,
  notes          VARCHAR(255) NULL,
  user_id        INT UNSIGNED NOT NULL,
  is_voided      TINYINT(1) NOT NULL DEFAULT 0,
  voided_at      DATETIME NULL,
  voided_by      INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cp_credit (credit_id),
  KEY idx_cp_store_date (store_id, paid_at),
  CONSTRAINT fk_cp_store  FOREIGN KEY (store_id)  REFERENCES stores(id),
  CONSTRAINT fk_cp_credit FOREIGN KEY (credit_id) REFERENCES credits(id),
  CONSTRAINT fk_cp_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_cp_voider FOREIGN KEY (voided_by) REFERENCES users(id),
  CONSTRAINT chk_cp_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. Expenses, withdrawals, supplier payments
-- ---------------------------------------------------------------------
CREATE TABLE expense_categories (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id  INT UNSIGNED NOT NULL,
  name      VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expcat_store_name (store_id, name),
  CONSTRAINT fk_expcat_store FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB;

-- Expenses never touch inventory or COGS (Part 5).
CREATE TABLE expenses (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  category_id    INT UNSIGNED NOT NULL,
  expense_date   DATE NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  description    VARCHAR(255) NULL,
  payment_method ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash',
  user_id        INT UNSIGNED NOT NULL,
  is_voided      TINYINT(1) NOT NULL DEFAULT 0,
  voided_at      DATETIME NULL,
  voided_by      INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exp_store_date (store_id, expense_date),
  CONSTRAINT fk_exp_store  FOREIGN KEY (store_id)    REFERENCES stores(id),
  CONSTRAINT fk_exp_cat    FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_exp_user   FOREIGN KEY (user_id)     REFERENCES users(id),
  CONSTRAINT fk_exp_voider FOREIGN KEY (voided_by)   REFERENCES users(id),
  CONSTRAINT chk_exp_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- A withdrawal that eats into capital is flagged, never blocked (2.5).
-- safe_amount_at_time is a snapshot so the flag stays explainable later.
CREATE TABLE withdrawals (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id            INT UNSIGNED NOT NULL,
  amount              DECIMAL(12,2) NOT NULL,
  withdrawn_at        DATETIME NOT NULL,
  note                VARCHAR(255) NULL,
  safe_amount_at_time DECIMAL(12,2) NOT NULL,
  exceeded_safe_limit TINYINT(1) NOT NULL DEFAULT 0,
  user_id             INT UNSIGNED NOT NULL,
  is_voided           TINYINT(1) NOT NULL DEFAULT 0,
  voided_at           DATETIME NULL,
  voided_by           INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wd_store_date (store_id, withdrawn_at),
  CONSTRAINT fk_wd_store  FOREIGN KEY (store_id)  REFERENCES stores(id),
  CONSTRAINT fk_wd_user   FOREIGN KEY (user_id)   REFERENCES users(id),
  CONSTRAINT fk_wd_voider FOREIGN KEY (voided_by) REFERENCES users(id),
  CONSTRAINT chk_wd_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- Supplier payments drive Outstanding Supplier Payables (approved proposal).
CREATE TABLE supplier_payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id       INT UNSIGNED NOT NULL,
  purchase_id    INT UNSIGNED NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,
  payment_method ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash',
  paid_at        DATETIME NOT NULL,
  notes          VARCHAR(255) NULL,
  user_id        INT UNSIGNED NOT NULL,
  is_voided      TINYINT(1) NOT NULL DEFAULT 0,
  voided_at      DATETIME NULL,
  voided_by      INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sp_purchase (purchase_id),
  KEY idx_sp_store_date (store_id, paid_at),
  CONSTRAINT fk_sp_store    FOREIGN KEY (store_id)    REFERENCES stores(id),
  CONSTRAINT fk_sp_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  CONSTRAINT fk_sp_user     FOREIGN KEY (user_id)     REFERENCES users(id),
  CONSTRAINT fk_sp_voider   FOREIGN KEY (voided_by)   REFERENCES users(id),
  CONSTRAINT chk_sp_amount  CHECK (amount > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. Cash: counts and the cash ledger
-- ---------------------------------------------------------------------
-- The Figma "record cash" action becomes a physical cash count. The
-- variance (counted - expected) is posted to the ledger as a count
-- adjustment, so Total Cash is always derived from the ledger.
CREATE TABLE cash_counts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id        INT UNSIGNED NOT NULL,
  counted_amount  DECIMAL(12,2) NOT NULL,
  expected_amount DECIMAL(12,2) NOT NULL,
  variance        DECIMAL(12,2) NOT NULL,
  note            VARCHAR(255) NULL,
  user_id         INT UNSIGNED NOT NULL,
  counted_at      DATETIME NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cc_store_date (store_id, counted_at),
  CONSTRAINT fk_cc_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_cc_user  FOREIGN KEY (user_id)  REFERENCES users(id),
  CONSTRAINT chk_cc_counted CHECK (counted_amount >= 0)
) ENGINE=InnoDB;

-- Append-only. amount is signed (positive in, negative out). Credit sales
-- never appear here until the customer pays (credit_payment rows).
-- Collected funds across cash, GCash and Maya are tracked by payment_method.
CREATE TABLE cash_ledger (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id           INT UNSIGNED NOT NULL,
  entry_type         ENUM('sale','credit_payment','refund','expense','withdrawal',
                          'supplier_payment','count_adjustment') NOT NULL,
  payment_method     ENUM('cash','gcash','maya') NOT NULL DEFAULT 'cash',
  amount             DECIMAL(12,2) NOT NULL,
  entry_date         DATETIME NOT NULL,
  sale_id            INT UNSIGNED NULL,
  credit_payment_id  INT UNSIGNED NULL,
  sale_return_id     INT UNSIGNED NULL,
  expense_id         INT UNSIGNED NULL,
  withdrawal_id      INT UNSIGNED NULL,
  supplier_payment_id INT UNSIGNED NULL,
  cash_count_id      INT UNSIGNED NULL,
  user_id            INT UNSIGNED NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cl_store_date (store_id, entry_date),
  KEY idx_cl_store_method (store_id, payment_method),
  CONSTRAINT fk_cl_store   FOREIGN KEY (store_id)            REFERENCES stores(id),
  CONSTRAINT fk_cl_sale    FOREIGN KEY (sale_id)             REFERENCES sales(id),
  CONSTRAINT fk_cl_cpay    FOREIGN KEY (credit_payment_id)   REFERENCES credit_payments(id),
  CONSTRAINT fk_cl_return  FOREIGN KEY (sale_return_id)      REFERENCES sale_returns(id),
  CONSTRAINT fk_cl_expense FOREIGN KEY (expense_id)          REFERENCES expenses(id),
  CONSTRAINT fk_cl_wd      FOREIGN KEY (withdrawal_id)       REFERENCES withdrawals(id),
  CONSTRAINT fk_cl_spay    FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
  CONSTRAINT fk_cl_count   FOREIGN KEY (cash_count_id)       REFERENCES cash_counts(id),
  CONSTRAINT fk_cl_user    FOREIGN KEY (user_id)             REFERENCES users(id),
  CONSTRAINT chk_cl_nonzero CHECK (amount <> 0),
  CONSTRAINT chk_cl_one_origin CHECK (
    (sale_id IS NOT NULL) + (credit_payment_id IS NOT NULL) + (sale_return_id IS NOT NULL)
    + (expense_id IS NOT NULL) + (withdrawal_id IS NOT NULL)
    + (supplier_payment_id IS NOT NULL) + (cash_count_id IS NOT NULL) = 1)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. Notes / tasks, audit log, barcode cache
-- ---------------------------------------------------------------------
CREATE TABLE notes_tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id     INT UNSIGNED NOT NULL,
  title        VARCHAR(160) NOT NULL,
  details      VARCHAR(1000) NULL,
  due_date     DATE NULL,
  status       ENUM('open','done','archived') NOT NULL DEFAULT 'open',
  completed_at DATETIME NULL,
  user_id      INT UNSIGNED NOT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notes_store_status (store_id, status),
  CONSTRAINT fk_notes_store FOREIGN KEY (store_id) REFERENCES stores(id),
  CONSTRAINT fk_notes_user  FOREIGN KEY (user_id)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id    INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,          -- e.g. sale.create, product.update
  entity_type VARCHAR(40) NOT NULL,
  entity_id   BIGINT UNSIGNED NULL,
  old_values  LONGTEXT NULL,                 -- JSON
  new_values  LONGTEXT NULL,                 -- JSON
  ip_address  VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_store_time (store_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB;

-- Local-first barcode lookup cache for the external API (rate-limit relief).
CREATE TABLE barcode_lookup_cache (
  barcode      VARCHAR(64) NOT NULL,
  was_found    TINYINT(1) NOT NULL,
  name         VARCHAR(200) NULL,
  category     VARCHAR(120) NULL,
  image_url    VARCHAR(500) NULL,
  fetched_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (barcode)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. Derived views (reports are derived, never the source of truth)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_credit_balances AS
SELECT c.id AS credit_id, c.store_id, c.sale_id, c.customer_name, c.customer_phone,
       c.original_amount, c.due_date, c.status,
       COALESCE(p.paid, 0) AS amount_paid,
       COALESCE(r.offset_total, 0) AS returned_offset,
       c.original_amount - COALESCE(p.paid, 0) - COALESCE(r.offset_total, 0) AS balance
FROM credits c
LEFT JOIN (SELECT credit_id, SUM(amount) AS paid
           FROM credit_payments WHERE is_voided = 0 GROUP BY credit_id) p
       ON p.credit_id = c.id
LEFT JOIN (SELECT sale_id, SUM(credit_offset_amount) AS offset_total
           FROM sale_returns GROUP BY sale_id) r
       ON r.sale_id = c.sale_id;

CREATE OR REPLACE VIEW v_purchase_payables AS
SELECT pu.id AS purchase_id, pu.store_id, pu.supplier_id, pu.purchase_date, pu.total_cost,
       COALESCE(sp.paid, 0) AS amount_paid,
       pu.total_cost - COALESCE(sp.paid, 0) AS outstanding
FROM purchases pu
LEFT JOIN (SELECT purchase_id, SUM(amount) AS paid
           FROM supplier_payments WHERE is_voided = 0 GROUP BY purchase_id) sp
       ON sp.purchase_id = pu.id
WHERE pu.status = 'completed';

CREATE OR REPLACE VIEW v_cash_balances AS
SELECT store_id, payment_method, SUM(amount) AS balance
FROM cash_ledger
GROUP BY store_id, payment_method;

CREATE OR REPLACE VIEW v_stock_value AS
SELECT store_id,
       ROUND(SUM(quantity_on_hand * cost_price), 2) AS stock_value,
       COUNT(*) AS product_count
FROM products
WHERE quantity_on_hand > 0
GROUP BY store_id;

-- ---------------------------------------------------------------------
-- 14. Triggers: ledger-only stock changes and no hard deletes
-- ---------------------------------------------------------------------
DELIMITER $$

-- New products cannot start with stock. Opening stock must be posted
-- through a stock_adjustments + inventory_movements pair.
CREATE TRIGGER trg_products_bi BEFORE INSERT ON products
FOR EACH ROW
BEGIN
  IF NEW.quantity_on_hand <> 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'STOCK_LEDGER_REQUIRED: new products must start at 0; post opening stock via the ledger';
  END IF;
END$$

-- quantity_on_hand may only change while the ledger trigger is writing.
CREATE TRIGGER trg_products_bu BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
  IF NEW.quantity_on_hand <> OLD.quantity_on_hand
     AND COALESCE(@capitally_ledger_write, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'STOCK_LEDGER_REQUIRED: quantity_on_hand cannot be edited directly';
  END IF;
END$$

-- Core ledger guard. Updating products first takes the row lock, so two
-- simultaneous sales cannot both pass the stock check on a stale count.
-- If a later constraint fails, InnoDB rolls back this statement entirely.
CREATE TRIGGER trg_inventory_movements_bi BEFORE INSERT ON inventory_movements
FOR EACH ROW
BEGIN
  DECLARE v_store INT UNSIGNED;
  DECLARE v_qty   DECIMAL(12,3);

  SELECT store_id INTO v_store FROM products WHERE id = NEW.product_id;
  IF v_store IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PRODUCT_NOT_FOUND';
  END IF;
  IF v_store <> NEW.store_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'STORE_MISMATCH: product belongs to another store';
  END IF;

  SET @capitally_ledger_write = 1;
  UPDATE products
     SET quantity_on_hand = quantity_on_hand + NEW.quantity_change
   WHERE id = NEW.product_id;
  SET @capitally_ledger_write = 0;

  SELECT quantity_on_hand INTO v_qty FROM products WHERE id = NEW.product_id;

  IF v_qty < 0 AND NEW.is_negative_override = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'INSUFFICIENT_STOCK: movement would make stock negative';
  END IF;

  SET NEW.quantity_after = v_qty;
END$$

-- Append-only ledgers.
CREATE TRIGGER trg_inventory_movements_bu BEFORE UPDATE ON inventory_movements
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: inventory_movements cannot be modified'$$
CREATE TRIGGER trg_inventory_movements_bd BEFORE DELETE ON inventory_movements
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: inventory_movements cannot be deleted'$$

CREATE TRIGGER trg_cash_ledger_bu BEFORE UPDATE ON cash_ledger
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: cash_ledger cannot be modified'$$
CREATE TRIGGER trg_cash_ledger_bd BEFORE DELETE ON cash_ledger
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: cash_ledger cannot be deleted'$$

CREATE TRIGGER trg_audit_log_bu BEFORE UPDATE ON audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: audit_log cannot be modified'$$
CREATE TRIGGER trg_audit_log_bd BEFORE DELETE ON audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'APPEND_ONLY: audit_log cannot be deleted'$$

-- Soft delete only: block DELETE on important business records.
CREATE TRIGGER trg_products_bd BEFORE DELETE ON products
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: deactivate the product instead'$$
CREATE TRIGGER trg_sales_bd BEFORE DELETE ON sales
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: cancel the sale instead'$$
CREATE TRIGGER trg_sale_items_bd BEFORE DELETE ON sale_items
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: sale items cannot be deleted'$$
CREATE TRIGGER trg_sale_returns_bd BEFORE DELETE ON sale_returns
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: returns cannot be deleted'$$
CREATE TRIGGER trg_purchases_bd BEFORE DELETE ON purchases
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: cancel the purchase instead'$$
CREATE TRIGGER trg_purchase_items_bd BEFORE DELETE ON purchase_items
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: purchase items cannot be deleted'$$
CREATE TRIGGER trg_credits_bd BEFORE DELETE ON credits
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: cancel the credit instead'$$
CREATE TRIGGER trg_credit_payments_bd BEFORE DELETE ON credit_payments
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: void the payment instead'$$
CREATE TRIGGER trg_expenses_bd BEFORE DELETE ON expenses
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: void the expense instead'$$
CREATE TRIGGER trg_withdrawals_bd BEFORE DELETE ON withdrawals
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: void the withdrawal instead'$$
CREATE TRIGGER trg_supplier_payments_bd BEFORE DELETE ON supplier_payments
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: void the payment instead'$$
CREATE TRIGGER trg_stock_adjustments_bd BEFORE DELETE ON stock_adjustments
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: adjustments cannot be deleted'$$
CREATE TRIGGER trg_cash_counts_bd BEFORE DELETE ON cash_counts
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SOFT_DELETE_ONLY: cash counts cannot be deleted'$$

DELIMITER ;
