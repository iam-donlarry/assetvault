# AssetVault — Enterprise Staff Asset, Catalog & Stock Ledger System

**AssetVault** is an enterprise-grade web application designed for modern IT, Operations, and HR departments to manage physical hardware assets, bulk consumable peripherals, employee custody handovers, maintenance lifecycles, and multi-room stock valuation.

---

## 1. System Overview

AssetVault replaces legacy spreadsheets with a relational architecture separating **what an item is** (Master Product Catalog) from **the physical units** (Serialized Assets) and **stock quantities** (Bulk Consumables):

* **Product Catalog (`product_models`)**: The master definition for hardware and consumables (Brand, Model, Specs, Tracking Type, Reorder Levels, and Weighted Average Unit Cost).
* **Serialized Assets (`assets`)**: Individual, high-value devices with unique serial numbers, asset tags (e.g. `AST-LAP-001`), depreciation, and dedicated custody tracking.
* **Bulk Inventory & Stock Ledger (`inventory_stock` & `inventory_transactions`)**: Multi-location consumable balances (mice, cables, adapters, ID cards) tracked without fake asset tags, backed by an immutable transaction audit ledger.
* **Custody & Assignments (`asset_assignments`)**: Staff equipment checkouts, handover agreements, condition assessments, and partial returns.
* **Employee Lifecycle**: Seamless integration with Onboarding asset bundles and Offboarding exit clearance queues.

---

## 2. Core Architectural Invariants

AssetVault strictly adheres to 13 enterprise invariants:

1. **Product vs. Asset Separation**: Master catalog entries define the specifications; `assets` table holds only serialized units.
2. **Zero Fake Assets Invariant**: Consumable peripherals (cables, mice, adapters) are managed strictly through location stock balances and transactions—never by fabricating fake serialized rows in `assets`.
3. **Canonical Product Uniqueness**: Products are uniquely identified by the tuple `(category_id, brand, model_name, model_number)`.
4. **Tracking Type Immutability**: A product model cannot switch between `serialized` and `bulk_quantity` once physical assets, stock balances, or assignment history exist.
5. **Unambiguous Stock Equations**:
   $$\text{Total Owned} = \text{Available} + \text{Assigned} + \text{Damaged} + \text{Under Repair}$$
6. **Weighted Average Cost (WAC)**: Incoming bulk batches automatically recalculate catalog unit valuation based on prior stock and purchase cost:
   $$\text{WAC}_{\text{new}} = \frac{(\text{Stock}_{\text{prior}} \times \text{WAC}_{\text{prior}}) + (\text{Qty}_{\text{new}} \times \text{Cost}_{\text{new}})}{\text{Stock}_{\text{prior}} + \text{Qty}_{\text{new}}}$$
7. **Concurrency-Safe Transfers**: Location-to-location transfers acquire exclusive row-level locks (`SELECT ... FOR UPDATE`) inside atomic database transactions.
8. **Immutable Transaction Audit Trail**: Every stock receipt, staff issuance, staff return, transfer, cycle adjustment, and write-off creates a permanent, append-only record in `inventory_transactions`.
9. **Partial Return Workflows**: Bulk assignments support returning a subset of issued items ($1 \le \text{return} \le \text{remaining}$), transitioning status between `active`, `partially_returned`, and `returned`.
10. **Condition-Based Stock Routing**: Items returned in `damaged` condition are automatically diverted to the destination room's damaged stock bucket.
11. **Dual Currency Financials**: Full primary (NGN) and secondary (USD) currency valuation with point-of-purchase exchange rates.
12. **Role-Based Access Control (RBAC)**: Fine-grained permissions across all 11 application modules.
13. **Zero Data Loss Guarantee**: All original physical assets and custody records from legacy systems remain bit-for-bit preserved.

---

## 3. Technology Stack

* **Backend**: PHP 8.1+ (Strict types, Object-Oriented Business Logic Layer, PDO with prepared statements).
* **Database**: MySQL 8.0+ / MariaDB 10.4+ (InnoDB engine, utf8mb4 encoding, strict foreign key constraints).
* **Web Server**: Apache 2.4+ with `mod_rewrite` enabled.
* **Frontend**: Vanilla HTML5, Modern CSS3 (Custom Design System matching `#12b1b0` Outfit aesthetics), Vanilla JavaScript (no external framework bloat).
* **Icons**: Lucide Icons CDN.
* **Typography**: Outfit (Google Fonts).

---

## 4. Installation & Setup

### Prerequisites
* [XAMPP](https://www.apachefriends.org/) (or standalone Apache + MySQL + PHP 8.1+).
* Web server root configured at `c:/xampp/htdocs/assetvault` (or virtual host pointing to root).

### Step-by-Step Installation

1. **Clone or Copy Codebase**:
   Place the project directory into your web root:
   ```bash
   c:\xampp\htdocs\assetvault
   ```

2. **Database Setup**:
   * Open phpMyAdmin or MySQL CLI:
     ```sql
     CREATE DATABASE `assetvault` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   * Import the canonical schema file:
     ```bash
     mysql -u root -p assetvault < c:\xampp\htdocs\assetvault\schema.sql
     ```

3. **Verify Configuration**:
   * Inspect `config/config.php` to ensure database credentials and URL match your environment:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'assetvault');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     define('BASE_URL', '/assetvault');
     ```

4. **Verify Apache `.htaccess`**:
   Ensure `mod_rewrite` is enabled in Apache's `httpd.conf`. The project includes `.htaccess` handling clean routing (`/catalog`, `/inventory`, `/assignments`, `/staff`, etc.).

5. **Sign In**:
   * Navigate to `http://localhost/assetvault/login.php` in your browser.
   * **Default Credentials**:
     * **Identifier**: `admin` (or `admin@bayebusinesssolutions.com`)
     * **Password**: `Admin@123456`

---

## 5. Directory Structure

```
assetvault/
├── classes/                     # Core Business Logic Layer (Auto-loaded)
│   ├── ProductManager.php       # Master Catalog CRUD, canonical rules, WAC
│   ├── InventoryManager.php     # Multi-location stock, transfers, cycle counts
│   └── AssignmentManager.php    # Dual checkout wizard, custody, partial returns
├── config/                      # Application & Environment Configuration
│   └── config.php               # Database constants, session bootstrap, autoload
├── includes/                    # Global Partials & Helpers
│   ├── auth.php                 # Session guards, login/logout, RBAC helpers
│   ├── db.php                   # Singleton PDO database connection
│   ├── functions.php            # Security sanitization, currency formatters
│   ├── header.php               # Universal topbar navigation & user badge
│   ├── sidebar.php              # Collapsible navigation drawer
│   └── footer.php               # Universal scripts & DOM initializers
├── modules/                     # Presentation & Feature Controllers
│   ├── catalog/                 # Product Catalog directory & 50vw offcanvas drawer
│   ├── inventory/               # Bulk Stock ledger, transfers, intake drawer
│   ├── assets/                  # Physical serialized asset registry & lifecycle
│   ├── assignments/             # Dual checkout wizard & return processing
│   ├── staff/                   # Employee directory & asset profiles
│   ├── maintenance/             # Service tickets, repairs & maintenance logs
│   ├── onboarding/              # New hire asset bundle provisioning
│   ├── offboarding/             # Staff exit clearance pipeline & return audits
│   ├── reports/                 # Analytics hub, CSV exports & valuation charts
│   └── settings/                # Category definitions, locations & system config
├── migration/                   # Database migration scripts
│   └── migrate_catalog_ledger.php
├── scratch/                     # Automated test suites and diagnostics
│   └── test_catalog_ledger.php  # 10-point E2E architectural verification suite
├── .htaccess                    # URL routing & clean endpoint rewrite rules
├── dashboard.php                # High-level KPI cards, valuation, quick actions
├── login.php                    # Authentication gateway
├── logout.php                   # Session termination
├── schema.sql                   # Canonical, production-ready database schema
├── README.md                    # Project documentation
└── DEV_RULES.md                 # Mandatory developer & schema maintenance rules
```

---

## 6. Testing & Automated Verification

AssetVault includes a comprehensive 10-point test suite verifying all 13 invariants (uniqueness, WAC recalculation, tracking lock, concurrency row-locks, Zero Fake Assets, partial returns, and physical cycle count adjustments):

Run from the command line:
```bash
php scratch/test_catalog_ledger.php
```

Expected Output:
```
=====================================================================
AssetVault — Catalog & Stock Ledger E2E Invariants Test Suite
=====================================================================
[TEST 1] Baseline Database Integrity...
  ✓ All 28 legacy assets remain intact and linked to product_models.
[TEST 2] Product Creation & Canonical Uniqueness Rules...
  ✓ Product created successfully.
  ✓ Canonical duplicate successfully rejected.
[TEST 3] Bulk Inventory Intake & WAC Recalculation...
  ✓ Batch 1: 50 units @ ₦10,000 received. Stock = 50, Avg Cost = ₦10,000.00
  ✓ Batch 2: 50 units @ ₦12,000 received. Stock = 100, Recalculated WAC = ₦11,000.00
[TEST 4] Tracking Type Immutability Enforced...
  ✓ Tracking type change successfully blocked.
[TEST 5] Multi-Location Atomic Stock Transfer...
  ✓ Transferred 25 units: Store = 75, Open Office = 25 (Total = 100).
  ✓ Over-transfer successfully rejected.
[TEST 6] Bulk Checkout & Zero Fake Assets Rule...
  ✓ ZERO FAKE ASSETS ASSERTION PASSED: No rows added to assets table.
  ✓ Stock Balances at Open Office: Available = 19, Assigned = 6, Total Owned = 25.
[TEST 7] Partial Returns & Automatic State Transitions...
  ✓ Over-return successfully rejected.
  ✓ Partial return of 2 units: Assignment Status = 'partially_returned'.
  ✓ Final return of 4 damaged units: Assignment Status = 'returned', Routed to Store Damaged Bucket = 4 units.
[TEST 8] Physical Cycle Count Discrepancy Adjustment...
  ✓ Cycle count adjustment recorded: Delta = -2, New Count = 73, Transaction Logged.
[TEST 9] Serialized Unit Checkout & Return Workflow...
  ✓ Serialized asset checked out to staff.
  ✓ Serialized asset returned to Store in good condition.
[TEST 10] Cleaning up test records...
  ✓ Cleaned up test artifacts.

=====================================================================
ALL 10 VERIFICATION TESTS PASSED WITH 100% INVARIANT COMPLIANCE!
=====================================================================
```
