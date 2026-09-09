<?php
// modules/assets/index.php - Asset Register & Inventory
declare(strict_types=1);

$active_page = 'assets';
$page_title = 'Asset Register';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('assets.view');

// Filter parameters
$search = trim($_GET['q'] ?? '');
$categoryFilter = !empty($_GET['category']) ? (int)$_GET['category'] : null;
$statusFilter = !empty($_GET['status']) ? trim($_GET['status']) : null;
$locationFilter = !empty($_GET['location']) ? (int)$_GET['location'] : null;
$productFilter = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : null;

// Build query
$where = ["a.lifecycle_status != 'disposed'"];
$params = [];

if ($search !== '') {
    $where[] = "(a.asset_tag LIKE :s OR a.serial_number LIKE :s OR a.item_name LIKE :s OR a.brand LIKE :s OR a.model LIKE :s OR e.full_name LIKE :s OR p.product_code LIKE :s)";
    $params[':s'] = "%$search%";
}

if ($categoryFilter) {
    $where[] = "a.category_id = :cat";
    $params[':cat'] = $categoryFilter;
}

if ($statusFilter) {
    $where[] = "a.lifecycle_status = :status";
    $params[':status'] = $statusFilter;
}

if ($locationFilter) {
    $where[] = "a.current_location_id = :loc";
    $params[':loc'] = $locationFilter;
}

if ($productFilter) {
    $where[] = "a.product_id = :prod";
    $params[':prod'] = $productFilter;
}

$whereSql = implode(' AND ', $where);

// Counts
$totalCountStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM assets a 
    LEFT JOIN product_models p ON a.product_id = p.id
    LEFT JOIN employees e ON a.current_holder_id = e.id 
    WHERE $whereSql
");
$totalCountStmt->execute($params);
$totalRows = (int)$totalCountStmt->fetchColumn();

// Fetch assets
$sql = "
    SELECT a.*, c.name AS category_name, c.is_serialized,
           l.name AS location_name,
           p.product_code, p.tracking_type,
           e.full_name AS custodian_name, e.staff_id AS custodian_staff_id, e.email AS custodian_email
    FROM assets a
    JOIN asset_categories c ON a.category_id = c.id
    JOIN locations l ON a.current_location_id = l.id
    LEFT JOIN product_models p ON a.product_id = p.id
    LEFT JOIN employees e ON a.current_holder_id = e.id
    WHERE $whereSql
    ORDER BY a.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// Fetch categories and locations for filter selects
$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Asset Register</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Central inventory of all company equipment, custody allocations, and serial identifiers.
        </p>
    </div>

    <div style="display: flex; gap: 0.75rem;">
        <?php if (hasPermission('assets.create')): ?>
        <a href="<?= BASE_URL ?>/assets/create" class="btn btn-primary">
            <i data-lucide="plus-circle"></i>
            <span>Register Asset</span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <form method="GET" action="<?= BASE_URL ?>/assets" style="display: grid; grid-template-columns: 2fr 1.2fr 1.2fr 1.2fr auto; gap: 1rem; align-items: end;">
        <div>
            <label class="form-label" style="font-size: 0.8rem;">Search Query</label>
            <input type="text" name="q" class="form-control" placeholder="Search tag, serial, model, custodian..." value="<?= e($search) ?>">
        </div>

        <div>
            <label class="form-label" style="font-size: 0.8rem;">Category</label>
            <select name="category" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $categoryFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="form-label" style="font-size: 0.8rem;">Status</label>
            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
                <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                <option value="in_repair" <?= $statusFilter === 'in_repair' ? 'selected' : '' ?>>In Repair</option>
                <option value="damaged" <?= $statusFilter === 'damaged' ? 'selected' : '' ?>>Damaged</option>
                <option value="lost" <?= $statusFilter === 'lost' ? 'selected' : '' ?>>Lost</option>
            </select>
        </div>

        <div>
            <label class="form-label" style="font-size: 0.8rem;">Location</label>
            <select name="location" class="form-control">
                <option value="">All Locations</option>
                <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $locationFilter === (int)$loc['id'] ? 'selected' : '' ?>>
                    <?= e($loc['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary" style="padding: 0.65rem 1rem;">
                <i data-lucide="filter"></i> Filter
            </button>
            <?php if ($search !== '' || $categoryFilter || $statusFilter || $locationFilter): ?>
            <a href="<?= BASE_URL ?>/assets" class="btn btn-outline" style="padding: 0.65rem 0.85rem;" title="Reset Filters">
                <i data-lucide="rotate-ccw"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Assets Table Container -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #ffffff;">
        <span style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">
            Showing <?= count($assets) ?> of <?= $totalRows ?> Assets
        </span>
    </div>

    <?php if (empty($assets)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
            <i data-lucide="search-x" style="width: 48px; height: 48px; opacity: 0.4; margin-bottom: 0.75rem;"></i>
            <h4>No assets match your search filters</h4>
            <p style="font-size: 0.875rem;">Try adjusting or clearing your search filters above.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset Tag</th>
                        <th>Item / Specifications</th>
                        <th>Category</th>
                        <th>Serial Number</th>
                        <th>Current Custodian</th>
                        <th>Location</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $asset): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($asset['asset_tag']) ?>" style="font-weight: 700; color: var(--primary-color);">
                                <?= e($asset['asset_tag']) ?>
                            </a>
                            <?php if (!empty($asset['legacy_asset_id'])): ?>
                                <span style="display: block; font-size: 0.725rem; color: var(--text-secondary);">Legacy: <?= e($asset['legacy_asset_id']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-primary);"><?= e($asset['item_name']) ?></div>
                            <div style="font-size: 0.775rem; color: var(--text-secondary);">
                                <?= e($asset['brand'] ?? '—') ?> <?= e($asset['model'] ? '(' . $asset['model'] . ')' : '') ?>
                            </div>
                            <?php if (!empty($asset['product_code'])): ?>
                                <a href="<?= BASE_URL ?>/catalog?search=<?= urlencode($asset['product_code']) ?>" style="display: inline-block; font-size: 0.7rem; font-family: monospace; color: var(--primary); background: rgba(18, 177, 176, 0.08); padding: 1px 5px; border-radius: 3px; text-decoration: none; margin-top: 2px;" title="View Catalog Master">
                                    Catalog: <?= e($asset['product_code']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size: 0.825rem; color: var(--text-primary); font-weight: 500;">
                                <?= e($asset['category_name']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($asset['serial_number'])): ?>
                                <code style="font-size: 0.8rem; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #0f172a;">
                                    <?= e($asset['serial_number']) ?>
                                </code>
                            <?php else: ?>
                                <span style="color: var(--text-secondary); font-size: 0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($asset['custodian_name'])): ?>
                                <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($asset['custodian_staff_id']) ?>" style="font-weight: 600; color: var(--text-primary); text-decoration: none;">
                                    <?= e($asset['custodian_name']) ?>
                                </a>
                                <span style="display: block; font-size: 0.75rem; color: var(--text-secondary);">
                                    ID: <?= e($asset['custodian_staff_id']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-secondary); font-size: 0.825rem; font-style: italic;">In Store</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-secondary);">
                            <?= e($asset['location_name']) ?>
                        </td>
                        <td>
                            <?= getBadgeForCondition($asset['condition_status']) ?>
                        </td>
                        <td>
                            <?= getBadgeForStatus($asset['lifecycle_status']) ?>
                        </td>
                        <td style="text-align: right;">
                            <div class="action-dropdown">
                                <button class="action-dropdown-toggle">Actions ▾</button>
                                <div class="action-dropdown-menu">
                                    <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($asset['asset_tag']) ?>" class="action-dropdown-item">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i> View Profile
                                    </a>
                                    <?php if ($asset['lifecycle_status'] === 'available' && hasPermission('assets.assign')): ?>
                                    <a href="<?= BASE_URL ?>/assignments/checkout?asset_id=<?= $asset['id'] ?>" class="action-dropdown-item">
                                        <i data-lucide="user-plus" style="width: 14px; height: 14px;"></i> Issue to Staff
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($asset['lifecycle_status'] === 'assigned' && hasPermission('assets.return')): ?>
                                    <a href="<?= BASE_URL ?>/assignments?return_asset_id=<?= $asset['id'] ?>" class="action-dropdown-item">
                                        <i data-lucide="arrow-down-left" style="width: 14px; height: 14px;"></i> Return Asset
                                    </a>
                                    <?php endif; ?>
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

<?php
include __DIR__ . '/../../includes/footer.php';
?>
