# AssetVault — Developer Guidelines & Architectural Rules

This document outlines the mandatory engineering standards, architectural invariants, and **strict database schema maintenance rules** for all developers working on AssetVault.

---

## 1. THE GOLDEN RULE OF SCHEMA SYNCHRONIZATION

> [!IMPORTANT]
> **`schema.sql` IS THE SOLE CANONICAL SOURCE OF TRUTH.**  
> Under no circumstances may a developer alter, add, or drop a column, table, index, foreign key, or ENUM value in the database without **simultaneously updating `schema.sql` in the exact same commit**.

### 1.1 Non-Negotiable Schema Rules

1. **Zero Schema Drift**: The live database and `schema.sql` must remain bit-for-bit identical at all times. A fresh database created by executing `mysql assetvault < schema.sql` must produce the exact structure, constraints, permissions, and seed accounts as the active production database.
2. **Field Changes & Table Alterations Procedure**:
   Whenever a feature requires a database schema change:
   - **Step 1: Write an Idempotent Migration Script**:
     Create a script in `migration/` (e.g. `migration/add_warranty_provider.php`) using `IF NOT EXISTS` or checking `SHOW COLUMNS FROM ... LIKE '...'` before executing `ALTER TABLE`.
   - **Step 2: Execute Migration on Live Database**:
     Run the migration to update the development database.
   - **Step 3: Immediately Update `schema.sql`**:
     Add the new column, constraint, or table definition to `schema.sql` in its proper chronological position.
   - **Step 4: Audit & Verify Synchronization**:
     Execute the schema audit tool:
     ```bash
     php scratch/audit_schema.php && python scratch/diff_schema.py
     ```
     Ensure the diff outputs **zero missing tables** and **zero column discrepancies**.
3. **Non-Destructive Migrations**:
   - Never run `DROP COLUMN` or destructive schema alterations on production data without automated data backfill and migration scripts.
   - When renaming or restructuring columns, write data migration routines that preserve all legacy records and staff custody histories.
4. **Foreign Key Integrity**:
   - All relations must use explicit InnoDB foreign keys with appropriate `ON UPDATE CASCADE` and `ON DELETE RESTRICT` (or `ON DELETE SET NULL` for optional references like `supplier_id`).
   - Never leave orphaned child rows.
5. **Permissions & Seed Data**:
   - If a new module or action is added, the corresponding permission slug (e.g. `equipment.calibrate`) must be inserted into `permissions` in `schema.sql` and bound to `role_permissions`.
   - Preserve the legacy Super Admin credentials (`admin` / `Admin@123456`) and default roles in `schema.sql`.

---

## 2. ARCHITECTURAL INVARIANTS & BOUNDARIES

### 2.1 The "Zero Fake Assets" Rule
* **Serialized Devices Only**: The `assets` table is reserved strictly for items with `tracking_type = 'serialized'` (laptops, monitors, smartphones, printers) that possess unique manufacturer serial numbers and individual asset tags.
* **Bulk Consumables & Accessories**: Peripherals (mice, keyboards, cables, power adapters, ID badges) have `tracking_type = 'bulk_quantity'` and exist **exclusively** in `inventory_stock` and `inventory_transactions`.
* **Prohibition**: Never insert dummy records into `assets` with fabricated serial numbers (e.g. `N/A`, `MOUSE-01`, `BATCH-50`) to track quantity.

### 2.2 Canonical Product Uniqueness
* Every model in `product_models` must be unique across the 4-tuple:
  $$\text{UNIQUE KEY} (\text{category\_id}, \text{brand}, \text{model\_name}, \text{model\_number})$$
* Before inserting or updating any catalog model, call `ProductManager::checkCanonicalExists()` to prevent duplicate catalog entries.

### 2.3 Tracking Type Immutability
* Once physical units (`assets`), inventory stock (`inventory_stock`), or custody records (`asset_assignments`) exist for a product model, its `tracking_type` is **permanently locked**.
* Mutation attempts must throw a `LogicException` via `ProductManager::canChangeTrackingType()`.

### 2.4 Unambiguous Stock Equation
* Stock balances must always satisfy the universal identity:
  $$\text{Total Owned} = \text{quantity\_available} + \text{quantity\_assigned} + \text{quantity\_damaged} + \text{quantity\_under\_repair}$$
* Never conflate "available stock" with "total owned inventory".

### 2.5 Weighted Average Cost (WAC) Timing
* When receiving new bulk stock batches in `InventoryManager::receiveStock()`, the Weighted Average Cost **must be recalculated BEFORE** incrementing `quantity_available` in `inventory_stock`, ensuring that the prior on-hand balance represents the pre-intake quantity:
  $$\text{WAC}_{\text{new}} = \frac{(\text{Stock}_{\text{prior}} \times \text{WAC}_{\text{prior}}) + (\text{Qty}_{\text{incoming}} \times \text{Cost}_{\text{incoming}})}{\text{Stock}_{\text{prior}} + \text{Qty}_{\text{incoming}}}$$

### 2.6 Concurrency & Multi-Location Row Locks
* All balance-modifying operations (intake receipts, location transfers, staff issues, returns, and cycle adjustments) must run inside an explicit database transaction (`$this->db->beginTransaction()`).
* Always acquire exclusive pessimistic row locks using `SELECT ... FOR UPDATE` before updating balances in `inventory_stock`.

### 2.7 Partial Return & Stock Bucket Routing
* Bulk checkouts allow partial returns ($1 \le \text{returned\_qty} \le \text{remaining\_assigned}$).
* State transitions:
  * If $\text{returned\_qty} < \text{assigned\_qty} \implies \text{status} = \text{'partially\_returned'}$
  * If $\text{returned\_qty} = \text{assigned\_qty} \implies \text{status} = \text{'returned'}$
* Items returned in `damaged` condition must be automatically routed to `quantity_damaged` in the destination room's stock bucket.

---

## 3. BACKEND CODING STANDARDS

### 3.1 Strict Typing & Architecture
* Every PHP file must begin with:
  ```php
  <?php
  declare(strict_types=1);
  ```
* All domain business logic belongs in `classes/` (`ProductManager`, `InventoryManager`, `AssignmentManager`). Feature files in `modules/` act solely as presentation controllers.

### 3.2 Security & Prepared Statements
* **Zero Raw SQL Interpolation**: Never concatenate user variables into SQL queries. Always use PDO prepared statements with parameter binding:
  ```php
  // Correct
  $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_tag = :tag");
  $stmt->execute([':tag' => $tag]);
  
  // Strictly Forbidden
  $pdo->query("SELECT * FROM assets WHERE asset_tag = '{$tag}'");
  ```
* **Disallowed Duplicate Named Parameters**: When binding parameters in PDO, never repeat named parameters (e.g. `:qty` twice in the same query). Use distinct placeholders (`:qty1`, `:qty2`) or indexed parameters.
* **Cross-Site Request Forgery (CSRF)**: Every `POST` form must include `<input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">` and validate via `verifyCsrfToken()`.
* **Output Escaping**: All dynamic variables rendered in HTML must be escaped using `e($string)` or `htmlspecialchars()`.

### 3.3 Authorization & RBAC
* Every module page must begin with authentication and permission checks:
  ```php
  requireLogin();
  if (!hasPermission('inventory.view')) {
      flashMessage('error', 'Unauthorized access.');
      redirect('dashboard');
  }
  ```
* Mutating actions (`POST`) must verify management permissions (e.g. `hasPermission('inventory.manage')`).

---

## 4. FRONTEND & UI/UX DESIGN STANDARDS

### 4.1 Design Consistency with Expense App / Staff Directory
* **Color Palette**:
  * Primary Accent: `#12b1b0` (Teal)
  * Primary Hover: `#0e8c8b`
  * Text Primary: `#0f172a` (Slate 900)
  * Text Secondary: `#64748b` (Slate 500)
  * Borders: `#e2e8f0` (Slate 200)
  * Backgrounds: `#f8fafc` / `#ffffff`
* **Typography**: Outfit (`font-family: 'Outfit', sans-serif`).
* **Icons**: Use Lucide Icons (`<i data-lucide="..."></i>`) initialized via `lucide.createIcons()`.

### 4.2 Form Drawers & Offcanvas Modals
* **50vw Slide-Out Drawers**: All creation and edit forms (Add Product, Edit Product, Receive Bulk Stock, Add Staff, Edit Staff) must use the **50vw offcanvas drawer** sliding from the right.
* **Drawer Header Clearance**: Offcanvas drawer z-index (`z-index: 9999`) must ensure headers are never occluded behind fixed top navigation bars.
* **Keyboard Accessibility**: Every drawer and modal must listen for `Escape` key events to close cleanly.
* **Zero Browser `alert()` Calls**: Never use browser-native `alert()` or `confirm()`. Use accessible custom modals, slide-out drawers, or flash banners.

### 4.3 Empty States & Direct Escape Hatches
* Never strand the user with empty select dropdowns.
* If a select depends on reference data (e.g. Bulk Products), always provide an inline action link (e.g. `+ Register New Product`) and a helpful guidance banner.

---

## 5. DEVELOPER CHECKLIST FOR DATABASE CHANGES

Before opening a pull request or merging any branch with database modifications, complete this checklist:

- [ ] Migration script created in `migration/` with non-destructive, idempotent SQL (`IF NOT EXISTS` / column checks).
- [ ] Migration tested on development database without data loss.
- [ ] **`schema.sql` updated** with the identical table, column, index, or constraint definitions.
- [ ] Automated schema diff executed:
  ```bash
  php scratch/audit_schema.php
  python scratch/diff_schema.py
  ```
  *(Must return 0 missing tables and 0 column differences).*
- [ ] End-to-end invariants test suite executed and passing:
  ```bash
  php scratch/test_catalog_ledger.php
  ```
- [ ] Web routes verified under active session without PHP warnings or notices.
