<?php
declare(strict_types=1);

// modules/inventory/index.php - Bulk Inventory Stock Ledger & Location Transfers
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

if (!hasPermission('inventory.view') && !hasPermission('assets.view')) {
    flashMessage('error', 'You do not have permission to view inventory.');
    redirect('dashboard');
}

$invManager = new InventoryManager($pdo);
$prodManager = new ProductManager($pdo);

$page_title = 'Bulk Inventory & Stock Ledger';
$active_page = 'inventory';

$currentTab = $_GET['tab'] ?? 'balances'; // 'balances' or 'ledger'
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'receive_stock') {
        if (!hasPermission('inventory.manage') && !hasPermission('assets.create')) {
            flashMessage('error', 'You do not have permission to receive stock.');
            redirect('inventory');
        }

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed.');
            redirect('inventory');
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $unitCost = $_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : null;
        $receivedDate = !empty($_POST['received_date']) ? trim($_POST['received_date']) : date('Y-m-d');
        $supplier = trim($_POST['supplier_name'] ?? '');
        $referenceNo = trim($_POST['reference_no'] ?? '');
        $batchNumber = trim($_POST['batch_number'] ?? '');
        $userNotes = trim($_POST['notes'] ?? '');

        $metaParts = [];
        if (!empty($referenceNo)) $metaParts[] = "PO/Ref: {$referenceNo}";
        if (!empty($supplier)) $metaParts[] = "Supplier: {$supplier}";
        if (!empty($batchNumber)) $metaParts[] = "Batch: {$batchNumber}";
        if ($receivedDate !== date('Y-m-d')) $metaParts[] = "Received: {$receivedDate}";

        $compositeNotes = !empty($metaParts) ? implode(' | ', $metaParts) : '';
        if (!empty($userNotes)) {
            $compositeNotes = $compositeNotes ? "{$compositeNotes}\n{$userNotes}" : $userNotes;
        }

        try {
            $txId = $invManager->receiveStock($productId, $locationId, $quantity, $unitCost, $currentUserId, $compositeNotes);
            logAudit('inventory.receive', 'inventory_stock', $productId, null, [
                'location_id'   => $locationId,
                'quantity'      => $quantity,
                'unit_cost'     => $unitCost,
                'reference_no'  => $referenceNo,
                'supplier'      => $supplier,
                'received_date' => $receivedDate
            ]);
            flashMessage('success', "Stock receipt of {$quantity} units recorded successfully into ledger.");
            redirect('inventory');
        } catch (Exception $e) {
            flashMessage('error', 'Stock intake error: ' . $e->getMessage());
            redirect('inventory');
        }
    } elseif ($action === 'transfer_stock') {
        if (!hasPermission('inventory.manage') && !hasPermission('assets.transfer')) {
            flashMessage('error', 'You do not have permission to transfer inventory.');
            redirect('inventory');
        }

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed.');
            redirect('inventory');
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        $fromLocationId = (int)($_POST['from_location_id'] ?? 0);
        $toLocationId = (int)($_POST['to_location_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        try {
            $txId = $invManager->transferStock($productId, $fromLocationId, $toLocationId, $quantity, $currentUserId, $notes);
            logAudit('inventory.transfer', 'inventory_stock', $productId, null, [
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'quantity' => $quantity
            ]);
            flashMessage('success', "Successfully transferred {$quantity} units between locations.");
            redirect('inventory');
        } catch (Exception $e) {
            flashMessage('error', 'Transfer error: ' . $e->getMessage());
            redirect('inventory');
        }
    } elseif ($action === 'adjust_stock') {
        if (!hasPermission('inventory.manage')) {
            flashMessage('error', 'You do not have permission to adjust inventory counts.');
            redirect('inventory');
        }

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed.');
            redirect('inventory');
        }

        $productId = (int)($_POST['product_id'] ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $bucket = $_POST['bucket'] ?? 'available';
        $newQuantity = (int)($_POST['new_quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Cycle Count');
        $notes = trim($_POST['notes'] ?? '');

        try {
            $invManager->adjustStock($productId, $locationId, $bucket, $newQuantity, $reason, $currentUserId, $notes);
            logAudit('inventory.adjust', 'inventory_stock', $productId, null, [
                'location_id' => $locationId,
                'bucket' => $bucket,
                'new_quantity' => $newQuantity,
                'reason' => $reason
            ]);
            flashMessage('success', "Stock adjustment for {$bucket} count saved successfully.");
            redirect('inventory');
        } catch (Exception $e) {
            flashMessage('error', 'Adjustment error: ' . $e->getMessage());
            redirect('inventory');
        }
    }
}

// Reference Data
$categories = $pdo->query("SELECT id, name, code FROM asset_categories ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code, building, floor FROM locations ORDER BY name ASC")->fetchAll();
$bulkProductsList = $pdo->query("
    SELECT p.id, p.product_code, p.brand, p.model_name, p.model_number, p.unit_of_measure, p.average_unit_cost, p.min_reorder_level,
           COALESCE(SUM(s.quantity_available), 0) AS total_available_now
    FROM product_models p
    LEFT JOIN inventory_stock s ON p.id = s.product_id
    WHERE p.tracking_type = 'bulk_quantity' AND p.is_active = 1
    GROUP BY p.id
    ORDER BY p.brand ASC, p.model_name ASC
")->fetchAll();

// Filters
$filterCategory = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterProduct = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$filterSearch = !empty($_GET['search']) ? trim($_GET['search']) : null;
$filterLowStock = !empty($_GET['low_stock']) ? true : false;

// Data Fetching
$stockSummaries = $invManager->getAllStockSummary([
    'category_id'    => $filterCategory,
    'search'         => $filterSearch,
    'low_stock_only' => $filterLowStock
]);

// If filtered by specific product
if ($filterProduct) {
    $stockSummaries = array_filter($stockSummaries, fn($s) => (int)$s['product_id'] === $filterProduct);
}

// Transaction Ledger Data
$transactions = [];
if ($currentTab === 'ledger') {
    $transactions = $invManager->getTransactions([
        'product_id' => $filterProduct,
        'search'     => $filterSearch
    ], 100);
}

// Summary Metrics
$totalAvailable = 0;
$totalAssigned = 0;
$totalDamaged = 0;
$totalUnderRepair = 0;
$totalValuation = 0.00;
$lowStockCount = 0;

foreach ($stockSummaries as $s) {
    $totalAvailable += (int)$s['total_available'];
    $totalAssigned += (int)$s['total_assigned'];
    $totalDamaged += (int)$s['total_damaged'];
    $totalUnderRepair += (int)$s['total_under_repair'];
    $totalValuation += (float)$s['total_valuation'];
    if ((int)$s['total_available'] <= (int)$s['min_reorder_level']) {
        $lowStockCount++;
    }
}
$totalOwned = $totalAvailable + $totalAssigned + $totalDamaged + $totalUnderRepair;

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
        <h1 style="margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="boxes" style="color: var(--primary); width: 28px; height: 28px;"></i>
            <span>Bulk Inventory & Stock Ledger</span>
        </h1>
        <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
            Location-level stock balances, multi-site transfers, and immutable transaction audit history.
        </p>
    </div>
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <?php if (hasPermission('inventory.manage') || hasPermission('assets.create')): ?>
        <button type="button" class="btn btn-primary" onclick="openReceiveStockDrawer()" style="display: inline-flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="package-plus" style="width: 18px; height: 18px;"></i>
            <span>Receive Bulk Stock</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs Navigation -->
<div style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border); margin-bottom: 1.5rem;">
    <a href="<?= BASE_URL ?>/inventory?tab=balances" class="btn <?= $currentTab === 'balances' ? 'btn-primary' : 'btn-outline' ?>" style="border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: none; display: inline-flex; align-items: center; gap: 0.45rem;">
        <i data-lucide="layout-grid" style="width: 16px; height: 16px;"></i>
        <span>Stock Balances & Locations</span>
    </a>
    <a href="<?= BASE_URL ?>/inventory?tab=ledger" class="btn <?= $currentTab === 'ledger' ? 'btn-primary' : 'btn-outline' ?>" style="border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: none; display: inline-flex; align-items: center; gap: 0.45rem;">
        <i data-lucide="history" style="width: 16px; height: 16px;"></i>
        <span>Transaction Ledger</span>
    </a>
</div>

<!-- KPI Metric Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(18, 177, 176, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="package" style="width: 22px; height: 22px;"></i>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Total Owned Stock</div>
            <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);"><?= number_format($totalOwned) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16, 185, 129, 0.1); color: #059669; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="check-circle" style="width: 22px; height: 22px;"></i>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Available on Hand</div>
            <div style="font-size: 1.4rem; font-weight: 700; color: #059669;"><?= number_format($totalAvailable) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(59, 130, 246, 0.1); color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="user-check" style="width: 22px; height: 22px;"></i>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Staff Custody</div>
            <div style="font-size: 1.4rem; font-weight: 700; color: #2563eb;"><?= number_format($totalAssigned) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(239, 68, 68, 0.1); color: #dc2626; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="alert-triangle" style="width: 22px; height: 22px;"></i>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Damaged / Repair</div>
            <div style="font-size: 1.4rem; font-weight: 700; color: #dc2626;"><?= number_format($totalDamaged + $totalUnderRepair) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(139, 92, 246, 0.1); color: #7c3aed; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="wallet" style="width: 22px; height: 22px;"></i>
        </div>
        <div>
            <div style="font-size: 0.75rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Stock Valuation</div>
            <div style="font-size: 1.3rem; font-weight: 700; color: var(--text-primary);"><?= formatMoney($totalValuation, DEFAULT_CURRENCY) ?></div>
        </div>
    </div>
</div>

<?php if ($currentTab === 'balances'): ?>
<!-- Filter Bar -->
<div class="card" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
    <form method="GET" action="<?= BASE_URL ?>/inventory" style="display: grid; grid-template-columns: 2fr 1.5fr 1.5fr auto; gap: 0.85rem; align-items: end;">
        <input type="hidden" name="tab" value="balances">

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Search Inventory</label>
            <div style="position: relative;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-secondary);"></i>
                <input type="text" name="search" class="form-control" placeholder="Product, brand, code..." value="<?= e($filterSearch ?? '') ?>" style="padding-left: 2rem;">
            </div>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Category</label>
            <select name="category_id" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Stock Alert Filter</label>
            <select name="low_stock" class="form-control">
                <option value="">All Stock Levels</option>
                <option value="1" <?= $filterLowStock ? 'selected' : '' ?>>Low Stock / Below Reorder Level</option>
            </select>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary" style="padding: 0.55rem 1rem;">Filter</button>
            <?php if ($filterCategory || $filterSearch || $filterLowStock): ?>
            <a href="<?= BASE_URL ?>/inventory?tab=balances" class="btn btn-outline" style="padding: 0.55rem 0.85rem;" title="Clear">
                <i data-lucide="rotate-ccw" style="width: 16px; height: 16px;"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Stock Balances Table with Collapsible Location Drilldown -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 120px;">Code</th>
                    <th>Product Model</th>
                    <th>Category</th>
                    <th style="width: 120px; text-align: center;">Available</th>
                    <th style="width: 120px; text-align: center;">Assigned</th>
                    <th style="width: 120px; text-align: center;">Damaged</th>
                    <th style="width: 130px; text-align: center;">Total Owned</th>
                    <th style="width: 130px; text-align: right;">Valuation</th>
                    <th style="width: 160px; text-align: right;">Operations</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($stockSummaries)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                        <i data-lucide="package-open" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; opacity: 0.4; display: block;"></i>
                        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">No bulk inventory records</div>
                        <p style="margin: 0; font-size: 0.875rem;">Receive bulk inventory to start tracking location balances.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($stockSummaries as $s): 
                    $locationsStock = $invManager->getStock((int)$s['product_id']);
                    $isLow = ((int)$s['total_available'] <= (int)$s['min_reorder_level']);
                ?>
                <tr style="background: #ffffff;">
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary); background: rgba(18, 177, 176, 0.08); padding: 0.2rem 0.45rem; border-radius: 4px; font-size: 0.85rem;">
                            <?= e($s['product_code']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem;">
                            <?= e($s['brand']) ?> <?= e($s['model_name']) ?>
                        </div>
                        <?php if (!empty($s['model_number'])): ?>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            Part #: <span style="font-family: monospace;"><?= e($s['model_number']) ?></span>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 0.8rem;">
                            <?= e($s['category_name']) ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-size: 1.05rem; font-weight: 700; color: <?= $isLow ? 'var(--danger)' : '#059669' ?>;">
                            <?= number_format((int)$s['total_available']) ?>
                        </span>
                        <?php if ($isLow && (int)$s['total_available'] > 0): ?>
                        <div style="font-size: 0.65rem; color: #b45309; font-weight: 700;">LOW STOCK (≤<?= $s['min_reorder_level'] ?>)</div>
                        <?php elseif ((int)$s['total_available'] === 0): ?>
                        <div style="font-size: 0.65rem; color: #b91c1c; font-weight: 700;">OUT OF STOCK</div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: 600; color: #2563eb;">
                        <?= number_format((int)$s['total_assigned']) ?>
                    </td>
                    <td style="text-align: center; font-weight: 600; color: #dc2626;">
                        <?= number_format((int)$s['total_damaged']) ?>
                    </td>
                    <td style="text-align: center; font-weight: 700; color: var(--text-primary);">
                        <?= number_format((int)$s['total_owned']) ?> <?= e($s['unit_of_measure']) ?>
                    </td>
                    <td style="text-align: right; font-weight: 600; color: var(--text-primary);">
                        <?= formatMoney((float)$s['total_valuation'], DEFAULT_CURRENCY) ?>
                        <div style="font-size: 0.725rem; color: var(--text-secondary);">@ <?= formatMoney((float)$s['average_unit_cost'], DEFAULT_CURRENCY) ?>/ea</div>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: inline-flex; align-items: center; gap: 0.4rem;">
                            <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="openTransferModal(<?= (int)$s['product_id'] ?>, '<?= e(addslashes($s['brand'] . ' ' . $s['model_name'])) ?>')" title="Transfer between locations">
                                <i data-lucide="arrow-left-right" style="width: 14px; height: 14px;"></i>
                                <span>Transfer</span>
                            </button>

                            <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;" onclick="openAdjustModal(<?= (int)$s['product_id'] ?>, '<?= e(addslashes($s['brand'] . ' ' . $s['model_name'])) ?>')" title="Cycle count adjustment">
                                <i data-lucide="sliders" style="width: 14px; height: 14px;"></i>
                            </button>
                        </div>
                    </td>
                </tr>

                <!-- Collapsible Location Breakdown Row -->
                <?php if (!empty($locationsStock)): ?>
                <tr style="background: #fafafa;">
                    <td colspan="9" style="padding: 0.6rem 1.5rem 0.85rem 2.5rem; border-top: none;">
                        <div style="font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 0.4rem; letter-spacing: 0.05em; display: flex; align-items: center; gap: 0.4rem;">
                            <i data-lucide="map-pin" style="width: 13px; height: 13px;"></i>
                            <span>Location Breakdown:</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem;">
                            <?php foreach ($locationsStock as $ls): ?>
                            <div style="background: #ffffff; border: 1px solid var(--border); border-radius: 6px; padding: 0.45rem 0.75rem; font-size: 0.8rem; display: flex; align-items: center; gap: 0.6rem;">
                                <div style="font-weight: 600; color: var(--text-primary);"><?= e($ls['location_name']) ?>:</div>
                                <div style="color: #059669; font-weight: 700;"><?= number_format((int)$ls['quantity_available']) ?> avail</div>
                                <?php if ((int)$ls['quantity_assigned'] > 0): ?>
                                <div style="color: #2563eb;">(<?= number_format((int)$ls['quantity_assigned']) ?> issued)</div>
                                <?php endif; ?>
                                <?php if ((int)$ls['quantity_damaged'] > 0): ?>
                                <div style="color: #dc2626;">(<?= number_format((int)$ls['quantity_damaged']) ?> dmg)</div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ====================================================================== -->
<!-- TAB 2: IMMUTABLE AUDIT TRANSACTION LEDGER                             -->
<!-- ====================================================================== -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 150px;">Timestamp</th>
                    <th style="width: 140px;">Tx Code</th>
                    <th style="width: 150px;">Transaction Type</th>
                    <th>Product Model</th>
                    <th style="width: 180px;">Routing / Location</th>
                    <th style="width: 100px; text-align: center;">Quantity</th>
                    <th style="width: 120px; text-align: right;">Unit Cost</th>
                    <th style="width: 130px;">Logged By</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                        <i data-lucide="history" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; opacity: 0.4; display: block;"></i>
                        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">No inventory transactions yet</div>
                        <p style="margin: 0; font-size: 0.875rem;">Intake receipts, transfers, and staff issues will stream here automatically.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($transactions as $t): 
                    $txTypeLabels = [
                        'purchase_receive'        => ['label' => 'Stock Intake', 'class' => 'badge-success', 'icon' => 'arrow-down-left'],
                        'staff_checkout'         => ['label' => 'Staff Issue', 'class' => 'badge-info', 'icon' => 'arrow-up-right'],
                        'staff_return'           => ['label' => 'Staff Return', 'class' => 'badge-primary', 'icon' => 'rotate-ccw'],
                        'location_transfer'      => ['label' => 'Location Transfer', 'class' => 'badge-secondary', 'icon' => 'arrow-left-right'],
                        'damage_write_down'      => ['label' => 'Damage Write-Down', 'class' => 'badge-danger', 'icon' => 'alert-triangle'],
                        'cycle_count_adjustment' => ['label' => 'Cycle Adjustment', 'class' => 'badge-warning', 'icon' => 'sliders'],
                        'repair_out'             => ['label' => 'Out to Repair', 'class' => 'badge-warning', 'icon' => 'wrench'],
                        'repair_in'              => ['label' => 'Back from Repair', 'class' => 'badge-success', 'icon' => 'check-circle'],
                        'disposal'               => ['label' => 'Disposal', 'class' => 'badge-secondary', 'icon' => 'trash-2']
                    ];
                    $meta = $txTypeLabels[$t['transaction_type']] ?? ['label' => $t['transaction_type'], 'class' => 'badge-secondary', 'icon' => 'activity'];
                ?>
                <tr>
                    <td style="font-size: 0.825rem; color: var(--text-secondary);">
                        <?= formatDate($t['created_at'], 'M d, Y H:i') ?>
                    </td>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--text-primary); font-size: 0.8rem;">
                            <?= e($t['transaction_code']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $meta['class'] ?>" style="font-size: 0.775rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <i data-lucide="<?= $meta['icon'] ?>" style="width: 12px; height: 12px;"></i>
                            <?= $meta['label'] ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.875rem;">
                            <?= e($t['brand']) ?> <?= e($t['model_name']) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= e($t['category_name']) ?></div>
                    </td>
                    <td style="font-size: 0.825rem;">
                        <?php if (!empty($t['from_location_name']) && !empty($t['to_location_name'])): ?>
                            <span><?= e($t['from_location_name']) ?></span>
                            <i data-lucide="arrow-right" style="width: 12px; height: 12px; display: inline; vertical-align: middle;"></i>
                            <span style="font-weight: 600; color: var(--text-primary);"><?= e($t['to_location_name']) ?></span>
                        <?php elseif (!empty($t['to_location_name'])): ?>
                            <span style="color: #059669; font-weight: 600;">→ <?= e($t['to_location_name']) ?></span>
                        <?php elseif (!empty($t['from_location_name'])): ?>
                            <span style="color: #dc2626;">← <?= e($t['from_location_name']) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; font-weight: 700; font-size: 0.925rem; color: <?= (int)$t['quantity'] >= 0 ? '#059669' : '#dc2626' ?>;">
                        <?= (int)$t['quantity'] > 0 ? '+' . (int)$t['quantity'] : (int)$t['quantity'] ?>
                    </td>
                    <td style="text-align: right; font-size: 0.85rem;">
                        <?= $t['unit_cost'] !== null ? formatMoney((float)$t['unit_cost'], DEFAULT_CURRENCY) : '—' ?>
                    </td>
                    <td style="font-size: 0.825rem; color: var(--text-secondary);">
                        <?= e($t['created_by_name'] ?? 'System') ?>
                    </td>
                    <td style="font-size: 0.825rem; color: var(--text-secondary); max-width: 250px;">
                        <?= e($t['notes'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ====================================================================== -->
<!-- 1. OFF-CANVAS DRAWER: RECEIVE BULK STOCK (50vw width)                 -->
<!-- ====================================================================== -->
<div class="offcanvas-overlay" id="receiveStockDrawer" onclick="handleDrawerBackdrop(event)">
    <div class="offcanvas-drawer" onclick="event.stopPropagation()">
        <div class="offcanvas-header">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="package-plus" style="color: var(--primary); width: 22px; height: 22px;"></i>
                    <span>Receive Bulk Stock</span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.825rem; color: var(--text-secondary);">
                    Record incoming bulk inventory batch, assign destination room, and update unit valuation.
                </p>
            </div>
            <button type="button" class="btn-icon-toggle" onclick="closeReceiveStockDrawer()" title="Close Drawer" style="border-radius: 50%; width: 32px; height: 32px; padding: 0;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/inventory" id="receiveStockForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0;">
            <input type="hidden" name="action" value="receive_stock">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="offcanvas-body">
                <div style="margin-bottom: 1.5rem;">
                    <?php if (empty($bulkProductsList)): ?>
                    <div style="background: #fffbeb; border: 1px solid #fef3c7; color: #92400e; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                        <div style="font-size: 0.85rem;">
                            <strong>No Bulk Products in Catalog:</strong> All existing products are configured as Serialized. Create a product with <em>Tracking Type: Bulk Quantity</em> first.
                        </div>
                        <a href="<?= BASE_URL ?>/catalog?open=add&tracking=bulk_quantity" target="_blank" class="btn btn-sm btn-primary" style="white-space: nowrap;">
                            <i data-lucide="plus"></i> Add Bulk Product
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Product Selection -->
                    <div class="form-group">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.35rem;">
                            <label class="form-label" for="rcv_product_id" style="margin-bottom: 0;">Select Bulk Product <span style="color: var(--danger);">*</span></label>
                            <a href="<?= BASE_URL ?>/catalog?open=add&tracking=bulk_quantity" target="_blank" style="font-size: 0.775rem; color: var(--primary); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem;">
                                <i data-lucide="external-link" style="width: 12px; height: 12px;"></i> Register New Product
                            </a>
                        </div>
                        <select name="product_id" id="rcv_product_id" class="form-control" required onchange="handleProductSelect(this)">
                            <option value="">Choose Catalog Product...</option>
                            <?php foreach ($bulkProductsList as $bp): ?>
                            <option value="<?= $bp['id'] ?>" 
                                data-cost="<?= $bp['average_unit_cost'] ?>" 
                                data-uom="<?= e($bp['unit_of_measure']) ?>"
                                data-stock="<?= $bp['total_available_now'] ?>"
                                data-brand="<?= e($bp['brand']) ?>"
                                data-model="<?= e($bp['model_name']) ?>"
                                data-code="<?= e($bp['product_code']) ?>">
                                <?= e($bp['brand']) ?> <?= e($bp['model_name']) ?> (<?= e($bp['product_code']) ?>) — Available: <?= $bp['total_available_now'] ?> <?= e($bp['unit_of_measure']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-secondary); font-size: 0.75rem;">Only products configured for bulk quantity tracking are listed.</small>
                    </div>

                    <!-- Live WAC & Stock Impact Preview Card -->
                    <div id="wacPreviewCard" style="display: none; background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1.25rem;">
                        <div style="font-size: 0.775rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                            <i data-lucide="calculator" style="width: 14px; height: 14px; color: var(--primary);"></i>
                            <span>Real-Time Valuation Impact</span>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; font-size: 0.825rem;">
                            <div>
                                <span style="color: var(--text-secondary); display: block;">Current Stock:</span>
                                <strong id="prev_stock_display" style="color: var(--text-primary);">0 units</strong>
                            </div>
                            <div>
                                <span style="color: var(--text-secondary); display: block;">Current Avg Cost:</span>
                                <strong id="prev_cost_display" style="color: var(--text-primary);"><?= DEFAULT_CURRENCY ?> 0.00</strong>
                            </div>
                            <div>
                                <span style="color: var(--text-secondary); display: block;">Projected New WAC:</span>
                                <strong id="projected_wac_display" style="color: var(--primary);"><?= DEFAULT_CURRENCY ?> 0.00</strong>
                            </div>
                        </div>
                        <div id="total_batch_cost_row" style="margin-top: 0.5rem; padding-top: 0.5rem; border-top: 1px dashed var(--border); font-size: 0.8rem; color: var(--text-secondary); display: flex; justify-content: space-between;">
                            <span>Total Batch Purchase Value:</span>
                            <strong id="batch_total_display" style="color: var(--text-primary);"><?= DEFAULT_CURRENCY ?> 0.00</strong>
                        </div>
                    </div>

                    <!-- Location & Quantity -->
                    <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_location_id">Receiving Location / Store <span style="color: var(--danger);">*</span></label>
                            <select name="location_id" id="rcv_location_id" class="form-control" required>
                                <option value="">Select Destination Room...</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= $loc['code'] === 'LOC-STORE' ? 'selected' : '' ?>>
                                    <?= e($loc['name']) ?> (<?= e($loc['building']) ?> - <?= e($loc['floor']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_quantity">Quantity Received <span style="color: var(--danger);">*</span></label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="number" name="quantity" id="rcv_quantity" class="form-control" min="1" placeholder="e.g. 50" required oninput="calculateWACPreview()">
                                <span id="rcv_uom_badge" style="font-size: 0.775rem; color: var(--text-secondary); white-space: nowrap; padding: 0.4rem 0.6rem; background: #f1f5f9; border-radius: 4px;">units</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cost & Date -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_unit_cost">Intake Unit Cost (<?= DEFAULT_CURRENCY ?>) <span style="color: var(--danger);">*</span></label>
                            <input type="number" step="0.01" name="unit_cost" id="rcv_unit_cost" class="form-control" placeholder="0.00" required oninput="calculateWACPreview()">
                            <small style="color: var(--text-secondary); font-size: 0.75rem;">Price per individual unit for this intake batch.</small>
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_received_date">Received Date <span style="color: var(--danger);">*</span></label>
                            <input type="date" name="received_date" id="rcv_received_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Supplier & Reference -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_supplier">Supplier / Vendor</label>
                            <input type="text" name="supplier_name" id="rcv_supplier" class="form-control" placeholder="e.g. OfficeTech Supplies Ltd">
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="rcv_reference">PO / Invoice Number</label>
                            <input type="text" name="reference_no" id="rcv_reference" class="form-control" placeholder="e.g. PO-2026-0412">
                        </div>
                    </div>

                    <!-- Batch Number & Notes -->
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label class="form-label" for="rcv_batch">Batch / Lot Number</label>
                        <input type="text" name="batch_number" id="rcv_batch" class="form-control" placeholder="e.g. LOT-2026-AUG-01 (Optional)">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="rcv_notes">Intake Notes / Quality Inspection</label>
                        <textarea name="notes" id="rcv_notes" class="form-control" rows="2" placeholder="e.g. Delivery verified in sealed box. Passed physical QC inspection."></textarea>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer" style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeReceiveStockDrawer()">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Receipt</button>
            </div>
        </form>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 2. MODAL: TRANSFER STOCK BETWEEN LOCATIONS                             -->
<!-- ====================================================================== -->
<div class="modal-overlay" id="transferModal" onclick="closeTransferModal()" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" onclick="event.stopPropagation()" style="width: 100%; max-width: 500px; padding: 1.5rem; margin: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="arrow-left-right" style="color: var(--primary); width: 20px; height: 20px;"></i>
                <span>Transfer Stock Between Locations</span>
            </h3>
            <button type="button" onclick="closeTransferModal()" style="background: none; border: none; cursor: pointer; color: var(--text-secondary);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/inventory">
            <input type="hidden" name="action" value="transfer_stock">
            <input type="hidden" name="product_id" id="xfer_product_id">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div style="margin-bottom: 1rem; font-size: 0.9rem; font-weight: 600; color: var(--text-primary);" id="xfer_product_label"></div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Source Location <span style="color: var(--danger);">*</span></label>
                    <select name="from_location_id" id="xfer_from_location" class="form-control" required>
                        <option value="">From Room...</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Destination Location <span style="color: var(--danger);">*</span></label>
                    <select name="to_location_id" id="xfer_to_location" class="form-control" required>
                        <option value="">To Room...</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Transfer Quantity <span style="color: var(--danger);">*</span></label>
                <input type="number" name="quantity" class="form-control" min="1" placeholder="e.g. 10" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reason / Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Replenishing sales floor inventory">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeTransferModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Execute Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 3. MODAL: CYCLE COUNT ADJUSTMENT                                      -->
<!-- ====================================================================== -->
<div class="modal-overlay" id="adjustModal" onclick="closeAdjustModal()" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" onclick="event.stopPropagation()" style="width: 100%; max-width: 500px; padding: 1.5rem; margin: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="sliders" style="color: var(--primary); width: 20px; height: 20px;"></i>
                <span>Cycle Count Adjustment</span>
            </h3>
            <button type="button" onclick="closeAdjustModal()" style="background: none; border: none; cursor: pointer; color: var(--text-secondary);">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/inventory">
            <input type="hidden" name="action" value="adjust_stock">
            <input type="hidden" name="product_id" id="adj_product_id">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div style="margin-bottom: 1rem; font-size: 0.9rem; font-weight: 600; color: var(--text-primary);" id="adj_product_label"></div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Location <span style="color: var(--danger);">*</span></label>
                    <select name="location_id" id="adj_location" class="form-control" required>
                        <option value="">Select Location...</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin: 0;">
                    <label class="form-label">Target Bucket <span style="color: var(--danger);">*</span></label>
                    <select name="bucket" class="form-control" required>
                        <option value="available">Available Stock</option>
                        <option value="damaged">Damaged Stock</option>
                        <option value="under_repair">Under Repair Stock</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">New Accurate Quantity <span style="color: var(--danger);">*</span></label>
                <input type="number" name="new_quantity" class="form-control" min="0" placeholder="Physical count result" required>
                <small style="color: var(--text-secondary); font-size: 0.75rem;">System computes delta and writes permanent adjustment ledger.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Reason for Discrepancy <span style="color: var(--danger);">*</span></label>
                <input type="text" name="reason" class="form-control" placeholder="e.g. End of month physical audit" required>
            </div>

            <div class="form-group">
                <label class="form-label">Audit Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Optional audit observations">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeAdjustModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReceiveStockDrawer() {
    const drawer = document.getElementById('receiveStockDrawer');
    if (drawer) {
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const first = document.getElementById('rcv_product_id');
            if (first) first.focus();
            if (window.lucide) lucide.createIcons();
        }, 150);
    }
}

function closeReceiveStockDrawer() {
    const drawer = document.getElementById('receiveStockDrawer');
    if (drawer) {
        drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function handleDrawerBackdrop(e) {
    if (e.target.id === 'receiveStockDrawer') {
        closeReceiveStockDrawer();
    }
}

function handleProductSelect(select) {
    const opt = select.options[select.selectedIndex];
    const card = document.getElementById('wacPreviewCard');
    const uomBadge = document.getElementById('rcv_uom_badge');
    
    if (opt && opt.value) {
        const cost = parseFloat(opt.getAttribute('data-cost')) || 0;
        const uom = opt.getAttribute('data-uom') || 'units';
        const stock = parseInt(opt.getAttribute('data-stock')) || 0;
        
        if (uomBadge) uomBadge.textContent = uom;
        
        const costInput = document.getElementById('rcv_unit_cost');
        if (costInput && !costInput.value) {
            costInput.value = cost > 0 ? cost.toFixed(2) : '';
        }
        
        if (card) {
            card.style.display = 'block';
            document.getElementById('prev_stock_display').textContent = stock + ' ' + uom;
            document.getElementById('prev_cost_display').textContent = '<?= DEFAULT_CURRENCY ?> ' + cost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        
        calculateWACPreview();
    } else {
        if (card) card.style.display = 'none';
    }
}

function calculateWACPreview() {
    const select = document.getElementById('rcv_product_id');
    if (!select || !select.value) return;
    
    const opt = select.options[select.selectedIndex];
    if (!opt) return;
    
    const currentStock = parseInt(opt.getAttribute('data-stock')) || 0;
    const currentCost = parseFloat(opt.getAttribute('data-cost')) || 0;
    const uom = opt.getAttribute('data-uom') || 'units';
    
    const qtyInput = document.getElementById('rcv_quantity');
    const costInput = document.getElementById('rcv_unit_cost');
    
    const newQty = parseInt(qtyInput ? qtyInput.value : 0) || 0;
    const newCost = parseFloat(costInput ? costInput.value : 0) || 0;
    
    const batchTotal = newQty * newCost;
    const totalQty = currentStock + newQty;
    
    let projectedWAC = 0;
    if (totalQty > 0) {
        if (currentStock <= 0 || currentCost <= 0) {
            projectedWAC = newCost;
        } else {
            projectedWAC = ((currentStock * currentCost) + (newQty * newCost)) / totalQty;
        }
    }
    
    const batchElem = document.getElementById('batch_total_display');
    if (batchElem) {
        batchElem.textContent = '<?= DEFAULT_CURRENCY ?> ' + batchTotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
    
    const wacElem = document.getElementById('projected_wac_display');
    if (wacElem) {
        wacElem.textContent = '<?= DEFAULT_CURRENCY ?> ' + projectedWAC.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}

function openTransferModal(productId, productName) {
    document.getElementById('xfer_product_id').value = productId;
    document.getElementById('xfer_product_label').textContent = 'Product: ' + productName;
    const modal = document.getElementById('transferModal');
    if (modal) {
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeTransferModal() {
    const modal = document.getElementById('transferModal');
    if (modal) modal.style.display = 'none';
}

function openAdjustModal(productId, productName) {
    document.getElementById('adj_product_id').value = productId;
    document.getElementById('adj_product_label').textContent = 'Product: ' + productName;
    const modal = document.getElementById('adjustModal');
    if (modal) {
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeAdjustModal() {
    const modal = document.getElementById('adjustModal');
    if (modal) modal.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReceiveStockDrawer();
        closeTransferModal();
        closeAdjustModal();
    }
});

<?php if (isset($_GET['open']) && $_GET['open'] === 'receive'): ?>
document.addEventListener('DOMContentLoaded', function() {
    openReceiveStockDrawer();
});
<?php endif; ?>
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
