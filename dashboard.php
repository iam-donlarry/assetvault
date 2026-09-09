<?php
// dashboard.php - AssetVault Executive Operations Dashboard
declare(strict_types=1);

$active_page = 'dashboard';
$page_title = 'Executive Dashboard';

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// Fetch Serialized Asset Metrics
$serTotal = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lifecycle_status != 'disposed'")->fetchColumn();
$serAssigned = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lifecycle_status = 'assigned'")->fetchColumn();
$serAvailable = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lifecycle_status = 'available'")->fetchColumn();
$serRepair = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lifecycle_status IN ('in_repair', 'damaged')")->fetchColumn();

// Fetch Bulk Inventory Metrics
$bulkStockStats = $pdo->query("
    SELECT 
        COALESCE(SUM(quantity_available), 0) AS bulk_available,
        COALESCE(SUM(quantity_assigned), 0) AS bulk_assigned,
        COALESCE(SUM(quantity_damaged), 0) AS bulk_damaged,
        COALESCE(SUM(quantity_under_repair), 0) AS bulk_repair
    FROM inventory_stock
")->fetch();

$bulkAvailable = (int)$bulkStockStats['bulk_available'];
$bulkAssigned = (int)$bulkStockStats['bulk_assigned'];
$bulkDamaged = (int)$bulkStockStats['bulk_damaged'];
$bulkRepair = (int)$bulkStockStats['bulk_repair'];
$bulkTotalOwned = $bulkAvailable + $bulkAssigned + $bulkDamaged + $bulkRepair;

// Unambiguous Quantity Equations
$totalAssets = $serTotal + $bulkTotalOwned;
$assignedAssets = $serAssigned + $bulkAssigned;
$availableAssets = $serAvailable + $bulkAvailable;
$repairAssets = $serRepair + $bulkDamaged + $bulkRepair;
$totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status = 'active'")->fetchColumn();

// Dual Currency Valuation (Serialized + Bulk WAC)
$valStmt = $pdo->query("
    SELECT 
        SUM(CASE WHEN purchase_currency = 'NGN' THEN purchase_cost ELSE 0 END) AS total_ngn,
        SUM(CASE WHEN purchase_currency = 'USD' THEN purchase_cost ELSE 0 END) AS total_usd
    FROM assets
    WHERE lifecycle_status != 'disposed'
");
$valuation = $valStmt->fetch();
$totalValNGN = (float)($valuation['total_ngn'] ?? 0);
$totalValUSD = (float)($valuation['total_usd'] ?? 0);

// Add Bulk Inventory Valuation
$bulkValStmt = $pdo->query("
    SELECT COALESCE(SUM((s.quantity_available + s.quantity_assigned + s.quantity_damaged + s.quantity_under_repair) * p.average_unit_cost), 0) AS bulk_val
    FROM inventory_stock s
    JOIN product_models p ON s.product_id = p.id
");
$bulkValuationNGN = (float)$bulkValStmt->fetchColumn();
$totalValNGN += $bulkValuationNGN;

// Low Stock Alert Count
$lowStockAlerts = $pdo->query("
    SELECT p.id, p.brand, p.model_name, p.product_code, p.min_reorder_level, COALESCE(SUM(s.quantity_available), 0) AS current_avail
    FROM product_models p
    LEFT JOIN inventory_stock s ON p.id = s.product_id
    WHERE p.tracking_type = 'bulk_quantity' AND p.is_active = 1
    GROUP BY p.id
    HAVING current_avail <= p.min_reorder_level
    ORDER BY current_avail ASC
")->fetchAll();

// Action Alerts
$pendingOffboardings = $pdo->query("
    SELECT o.*, e.full_name, e.staff_id, e.job_title, d.name AS dept_name
    FROM offboarding_requests o
    JOIN employees e ON o.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE o.clearance_status = 'pending'
    ORDER BY o.initiation_date DESC
    LIMIT 5
")->fetchAll();

$upcomingMaintenance = $pdo->query("
    SELECT a.*, c.name AS category_name
    FROM assets a
    JOIN asset_categories c ON a.category_id = c.id
    WHERE a.next_maintenance_date IS NOT NULL 
      AND a.next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND a.lifecycle_status != 'disposed'
    ORDER BY a.next_maintenance_date ASC
    LIMIT 5
")->fetchAll();

// Recent Assignments / Activity (Supporting Serialized & Bulk)
$recentAssignments = $pdo->query("
    SELECT asg.*, 
           a.asset_tag, a.item_name AS asset_item_name, a.brand AS asset_brand, a.model AS asset_model,
           p.product_code, p.brand AS prod_brand, p.model_name AS prod_model_name, p.tracking_type,
           COALESCE(c_asset.name, c_prod.name) AS category_name,
           e.full_name AS staff_name, e.staff_id, e.email AS staff_email
    FROM asset_assignments asg
    LEFT JOIN assets a ON asg.asset_id = a.id
    LEFT JOIN asset_categories c_asset ON a.category_id = c_asset.id
    LEFT JOIN product_models p ON asg.product_id = p.id
    LEFT JOIN asset_categories c_prod ON p.category_id = c_prod.id
    JOIN employees e ON asg.employee_id = e.id
    ORDER BY asg.created_at DESC
    LIMIT 6
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner with Frosted Quick Actions -->
<div style="background: linear-gradient(135deg, #12b1b0 0%, #0d8a89 100%); border-radius: var(--radius); padding: 1.75rem 2rem; color: #ffffff; margin-bottom: 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; box-shadow: 0 4px 20px rgba(18, 177, 176, 0.25);">
    <div>
        <h2 style="color: #ffffff; font-size: 1.65rem; margin-bottom: 0.25rem; font-weight: 700;">
            Welcome, <?= e($_SESSION['user_name'] ?? 'Administrator') ?> 👋
        </h2>
        <p style="color: rgba(255, 255, 255, 0.88); font-size: 0.925rem; margin-bottom: 0;">
            Staff hardware allocation, asset lifecycle, and custody management overview.
        </p>
    </div>
    
    <div style="display: flex; gap: 0.65rem; flex-wrap: wrap;">
        <?php if (hasPermission('assets.create')): ?>
        <a href="<?= BASE_URL ?>/assets/create" class="btn-quick">
            <i data-lucide="plus-circle"></i>
            <span>New Asset</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('assets.assign')): ?>
        <a href="<?= BASE_URL ?>/assignments/checkout" class="btn-quick">
            <i data-lucide="arrow-up-right"></i>
            <span>Issue Asset</span>
        </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/staff" class="btn-quick">
            <i data-lucide="users"></i>
            <span>Staff Directory</span>
        </a>
    </div>
</div>

<!-- KPI Stat Cards Row -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.75rem;">
    <!-- Card 1: Total Assets -->
    <div class="stat-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
            <span style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">Total Equipment</span>
            <div class="stat-icon bg-primary-light">
                <i data-lucide="boxes" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: var(--text-primary); line-height: 1.1; margin-bottom: 0.35rem;">
            <?= number_format($totalAssets) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary);">
            <?= $serTotal ?> serialized • <?= $bulkTotalOwned ?> bulk units
        </div>
    </div>

    <!-- Card 2: Assigned Assets -->
    <div class="stat-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
            <span style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">Assigned Custody</span>
            <div class="stat-icon" style="background: #e0f2fe; color: #0284c7;">
                <i data-lucide="user-check" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #0284c7; line-height: 1.1; margin-bottom: 0.35rem;">
            <?= number_format($assignedAssets) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary);">
            <?= $serAssigned ?> serialized • <?= $bulkAssigned ?> bulk in custody
        </div>
    </div>

    <!-- Card 3: Available Inventory -->
    <div class="stat-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
            <span style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">Available Store</span>
            <div class="stat-icon" style="background: #ecfdf5; color: #10b981;">
                <i data-lucide="package-check" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #10b981; line-height: 1.1; margin-bottom: 0.35rem;">
            <?= number_format($availableAssets) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary);">
            <?= $serAvailable ?> serialized • <?= $bulkAvailable ?> bulk available
        </div>
    </div>

    <!-- Card 4: In Repair / Damaged -->
    <div class="stat-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
            <span style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">Repair / Damaged</span>
            <div class="stat-icon" style="background: #fef2f2; color: #ef4444;">
                <i data-lucide="alert-triangle" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #ef4444; line-height: 1.1; margin-bottom: 0.35rem;">
            <?= number_format($repairAssets) ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary);">
            <?= $serRepair ?> serialized • <?= $bulkDamaged + $bulkRepair ?> bulk damaged
        </div>
    </div>

    <!-- Card 5: Dual Currency Valuation -->
    <div class="stat-card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
            <span style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary);">Asset & Stock Valuation</span>
            <div class="stat-icon" style="background: #fef3c7; color: #d97706;">
                <i data-lucide="coins" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <div style="font-size: 1.45rem; font-weight: 800; color: var(--text-primary); line-height: 1.1; margin-bottom: 0.35rem;">
            <?= formatMoney($totalValNGN, 'NGN') ?>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-secondary);">
            Dual: <?= formatMoney($totalValUSD, 'USD') ?>
        </div>
    </div>
</div>

<!-- Main Content Grid: Recent Activity vs Action Required -->
<div style="display: grid; grid-template-columns: 1.55fr 1fr; gap: 1.5rem; margin-bottom: 1.75rem;">
    <!-- Left Column: Recent Custody Assignments -->
    <div class="card" style="margin-bottom: 0;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <div>
                <h3 style="font-size: 1.1rem; margin-bottom: 0.2rem;">Recent Custody Assignments</h3>
                <p style="font-size: 0.825rem; color: var(--text-secondary); margin-bottom: 0;">Latest equipment checked out to staff members</p>
            </div>
            <a href="<?= BASE_URL ?>/assignments" class="btn btn-outline btn-sm">View All</a>
        </div>

        <?php if (empty($recentAssignments)): ?>
            <div style="text-align: center; padding: 2.5rem 1rem; color: var(--text-secondary);">
                <i data-lucide="inbox" style="width: 42px; height: 42px; margin-bottom: 0.75rem; opacity: 0.5;"></i>
                <p style="font-size: 0.9rem; margin-bottom: 0.5rem;">No assignment records found yet.</p>
                <a href="<?= BASE_URL ?>/assignments/checkout" class="btn btn-primary btn-sm">Issue First Asset</a>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Asset Tag / Model</th>
                            <th>Staff Custodian</th>
                            <th>Issued Date</th>
                            <th>Condition</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAssignments as $row): 
                            $isSerialized = !empty($row['asset_id']);
                            $title = $isSerialized ? ($row['asset_tag'] ?: 'Serialized Asset') : ($row['product_code'] ?: 'Bulk Product');
                            $subtitle = $isSerialized 
                                ? ($row['category_name'] . ' — ' . $row['asset_item_name'])
                                : ($row['category_name'] . ' — ' . $row['prod_brand'] . ' ' . $row['prod_model_name'] . ' (Qty: ' . $row['quantity'] . ')');
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
                                    <span><?= e($title) ?></span>
                                    <?php if (!$isSerialized): ?>
                                    <span class="badge" style="font-size: 0.65rem; background: #ecfdf5; color: #047857; padding: 1px 5px;">Bulk</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                    <?= e($subtitle) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 500; color: var(--text-primary);">
                                    <?= e($row['staff_name']) ?>
                                </div>
                                <div style="font-size: 0.775rem; color: var(--text-secondary);">
                                    ID: <?= e($row['staff_id']) ?>
                                </div>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                <?= formatDate($row['assigned_date']) ?>
                            </td>
                            <td>
                                <?= getBadgeForCondition($row['condition_at_issue']) ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="action-dropdown">
                                    <button class="action-dropdown-toggle">Actions ▾</button>
                                    <div class="action-dropdown-menu">
                                        <?php if ($isSerialized && !empty($row['asset_tag'])): ?>
                                        <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($row['asset_tag']) ?>" class="action-dropdown-item">
                                            <i data-lucide="eye" style="width: 14px; height: 14px;"></i> View Asset
                                        </a>
                                        <?php else: ?>
                                        <a href="<?= BASE_URL ?>/inventory?product_id=<?= (int)$row['product_id'] ?>" class="action-dropdown-item">
                                            <i data-lucide="boxes" style="width: 14px; height: 14px;"></i> View Stock
                                        </a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($row['staff_id']) ?>" class="action-dropdown-item">
                                            <i data-lucide="user" style="width: 14px; height: 14px;"></i> Staff Profile
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Priority Alerts & Workflows -->
    <div style="display: flex; flex-direction: column; gap: 1.5rem;">
        <!-- Low Bulk Stock Alert (if any) -->
        <?php if (!empty($lowStockAlerts)): ?>
        <div class="card" style="margin-bottom: 0; border-left: 4px solid #f59e0b;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="alert-octagon" style="width: 18px; height: 18px; color: #d97706;"></i>
                    <h3 style="font-size: 1rem; margin-bottom: 0; color: #92400e;">Low Bulk Stock Warning</h3>
                </div>
                <span class="badge badge-warning" style="background: #fef3c7; color: #b45309;"><?= count($lowStockAlerts) ?> Items</span>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">
                Consumable inventory products at or below minimum threshold:
            </p>
            <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 0.75rem;">
                <?php foreach (array_slice($lowStockAlerts, 0, 3) as $lsa): ?>
                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.825rem; background: #fffbeb; padding: 0.45rem 0.65rem; border-radius: 4px;">
                    <div>
                        <strong><?= e($lsa['brand']) ?> <?= e($lsa['model_name']) ?></strong>
                        <span style="color: var(--text-secondary); font-size: 0.75rem;">(<?= e($lsa['product_code']) ?>)</span>
                    </div>
                    <span style="color: #b91c1c; font-weight: 700;">
                        <?= (int)$lsa['current_avail'] ?> left (≤<?= (int)$lsa['min_reorder_level'] ?>)
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <a href="<?= BASE_URL ?>/inventory?low_stock=1" class="btn btn-outline btn-sm" style="width: 100%; text-align: center; justify-content: center;">
                Inspect Low Stock Items →
            </a>
        </div>
        <?php endif; ?>
        <!-- Pending Exit Clearances -->
        <div class="card" style="margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="user-minus" style="width: 18px; height: 18px; color: #ef4444;"></i>
                    <h3 style="font-size: 1rem; margin-bottom: 0;">Exit Clearance Required</h3>
                </div>
                <span class="badge badge-warning"><?= count($pendingOffboardings) ?> Pending</span>
            </div>

            <?php if (empty($pendingOffboardings)): ?>
                <div style="padding: 1.5rem 1rem; text-align: center; color: var(--text-secondary); font-size: 0.85rem;">
                    <i data-lucide="check-circle" style="width: 32px; height: 32px; color: #10b981; margin-bottom: 0.5rem;"></i>
                    <p style="margin-bottom: 0;">All exiting employee assets cleared.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($pendingOffboardings as $offb): ?>
                    <div style="padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: space-between; background: #fafafa;">
                        <div>
                            <div style="font-weight: 600; font-size: 0.875rem;"><?= e($offb['full_name']) ?> (<?= e($offb['staff_id']) ?>)</div>
                            <div style="font-size: 0.775rem; color: var(--text-secondary);"><?= e($offb['dept_name']) ?> — Exit Date: <?= formatDate($offb['target_exit_date']) ?></div>
                        </div>
                        <a href="<?= BASE_URL ?>/offboarding/clearance/<?= urlencode($offb['staff_id']) ?>" class="btn btn-primary btn-sm" style="padding: 0.35rem 0.7rem; font-size: 0.775rem;">
                            Clear
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Maintenance & Health Alerts -->
        <div class="card" style="margin-bottom: 0;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="wrench" style="width: 18px; height: 18px; color: #f59e0b;"></i>
                    <h3 style="font-size: 1rem; margin-bottom: 0;">Maintenance Due (30 Days)</h3>
                </div>
                <a href="<?= BASE_URL ?>/maintenance" class="btn-link" style="font-size: 0.8rem; font-weight: 600;">View All</a>
            </div>

            <?php if (empty($upcomingMaintenance)): ?>
                <div style="padding: 1.5rem 1rem; text-align: center; color: var(--text-secondary); font-size: 0.85rem;">
                    <i data-lucide="shield-check" style="width: 32px; height: 32px; color: #10b981; margin-bottom: 0.5rem;"></i>
                    <p style="margin-bottom: 0;">No hardware maintenance due within 30 days.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($upcomingMaintenance as $mItem): 
                        $isOverdue = strtotime($mItem['next_maintenance_date']) <= time();
                    ?>
                    <div style="padding: 0.75rem; border: 1px solid var(--border); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: space-between; background: <?= $isOverdue ? '#fff1f2' : '#fafafa' ?>;">
                        <div>
                            <div style="font-weight: 600; font-size: 0.85rem;">
                                <?= e($mItem['asset_tag']) ?> — <?= e($mItem['item_name']) ?>
                            </div>
                            <div style="font-size: 0.775rem; color: <?= $isOverdue ? '#e11d48' : 'var(--text-secondary)' ?>;">
                                <?= $isOverdue ? 'Overdue since ' : 'Due: ' ?><?= formatDate($mItem['next_maintenance_date']) ?>
                            </div>
                        </div>
                        <span class="badge <?= $isOverdue ? 'badge-danger' : 'badge-warning' ?>" style="font-size: 0.7rem;">
                            <?= $isOverdue ? 'Overdue' : 'Due Soon' ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/includes/footer.php';
?>
