<?php
declare(strict_types=1);

// modules/catalog/index.php - Enterprise Product Catalog Management
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

if (!hasPermission('catalog.view') && !hasPermission('assets.view')) {
    flashMessage('error', 'You do not have permission to view the product catalog.');
    redirect('dashboard');
}

$productManager = new ProductManager($pdo);
$page_title = 'Product Catalog';
$active_page = 'catalog';

$drawerError = '';
$formData = [];

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_product') {
        if (!hasPermission('catalog.manage') && !hasPermission('assets.create')) {
            flashMessage('error', 'You do not have permission to create catalog products.');
            redirect('catalog');
        }

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed (CSRF token mismatch).');
            redirect('catalog');
        }

        $formData = $_POST;

        try {
            $newId = $productManager->create([
                'category_id'           => (int)($_POST['category_id'] ?? 0),
                'brand'                 => trim($_POST['brand'] ?? ''),
                'model_name'            => trim($_POST['model_name'] ?? ''),
                'model_number'          => trim($_POST['model_number'] ?? ''),
                'tracking_type'         => $_POST['tracking_type'] ?? 'serialized',
                'unit_of_measure'       => trim($_POST['unit_of_measure'] ?? 'units'),
                'specifications'        => trim($_POST['specifications'] ?? ''),
                'min_reorder_level'     => (int)($_POST['min_reorder_level'] ?? 0),
                'default_purchase_cost' => $_POST['default_purchase_cost'] !== '' ? (float)$_POST['default_purchase_cost'] : null,
                'is_active'             => isset($_POST['is_active']) ? 1 : 0
            ]);

            logAudit('catalog.create', 'product_models', $newId, null, [
                'brand' => trim($_POST['brand'] ?? ''),
                'model_name' => trim($_POST['model_name'] ?? ''),
                'tracking_type' => $_POST['tracking_type'] ?? 'serialized'
            ]);

            flashMessage('success', "Product '" . htmlspecialchars(trim($_POST['brand']) . ' ' . trim($_POST['model_name'])) . "' registered in catalog successfully.");
            redirect('catalog');
        } catch (Exception $e) {
            $drawerError = $e->getMessage();
        }
    } elseif ($action === 'update_product') {
        if (!hasPermission('catalog.manage') && !hasPermission('assets.edit')) {
            flashMessage('error', 'You do not have permission to edit catalog products.');
            redirect('catalog');
        }

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed (CSRF token mismatch).');
            redirect('catalog');
        }

        $productId = (int)($_POST['product_id'] ?? 0);

        try {
            $productManager->update($productId, [
                'category_id'           => (int)($_POST['category_id'] ?? 0),
                'brand'                 => trim($_POST['brand'] ?? ''),
                'model_name'            => trim($_POST['model_name'] ?? ''),
                'model_number'          => trim($_POST['model_number'] ?? ''),
                'tracking_type'         => $_POST['tracking_type'] ?? 'serialized',
                'unit_of_measure'       => trim($_POST['unit_of_measure'] ?? 'units'),
                'specifications'        => trim($_POST['specifications'] ?? ''),
                'min_reorder_level'     => (int)($_POST['min_reorder_level'] ?? 0),
                'default_purchase_cost' => $_POST['default_purchase_cost'] !== '' ? (float)$_POST['default_purchase_cost'] : null,
                'is_active'             => isset($_POST['is_active']) ? 1 : 0
            ]);

            logAudit('catalog.update', 'product_models', $productId, null, [
                'brand' => trim($_POST['brand'] ?? ''),
                'model_name' => trim($_POST['model_name'] ?? '')
            ]);

            flashMessage('success', "Catalog product updated successfully.");
            redirect('catalog');
        } catch (Exception $e) {
            flashMessage('error', 'Update failed: ' . $e->getMessage());
            redirect('catalog?edit=' . $productId);
        }
    }
}

// Filters & Data Retrieval
$filterCategory = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$filterTracking = !empty($_GET['tracking_type']) ? trim($_GET['tracking_type']) : null;
$filterSearch   = !empty($_GET['search']) ? trim($_GET['search']) : null;
$filterStatus   = isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int)$_GET['is_active'] : null;

$products = $productManager->getAll([
    'category_id'   => $filterCategory,
    'tracking_type' => $filterTracking,
    'search'        => $filterSearch,
    'is_active'     => $filterStatus
]);

// Categories for filter and drawer
$categories = $pdo->query("SELECT id, name, code, is_serialized FROM asset_categories ORDER BY name ASC")->fetchAll();

// Aggregated Stats
$totalProducts = count($products);
$serializedProducts = 0;
$bulkProducts = 0;
$totalUnitsTracked = 0;

foreach ($products as $p) {
    if ($p['tracking_type'] === 'serialized') {
        $serializedProducts++;
        $totalUnitsTracked += (int)$p['serialized_asset_count'];
    } else {
        $bulkProducts++;
        $totalUnitsTracked += ((int)$p['bulk_available_qty'] + (int)$p['bulk_assigned_qty'] + (int)$p['bulk_damaged_qty'] + (int)$p['bulk_repair_qty']);
    }
}

// Edit target if requested
$editProductTarget = null;
if (!empty($_GET['edit'])) {
    $editProductTarget = $productManager->getById((int)$_GET['edit']);
    if ($editProductTarget) {
        $editProductTarget['can_change_tracking'] = $productManager->canChangeTrackingType(
            (int)$editProductTarget['id'],
            $editProductTarget['tracking_type'] === 'serialized' ? 'bulk_quantity' : 'serialized'
        );
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div>
        <h1 style="margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="boxes" style="color: var(--primary); width: 28px; height: 28px;"></i>
            <span>Product Catalog</span>
        </h1>
        <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
            Master definitions of hardware models, consumable items, tracking modes, and specifications.
        </p>
    </div>
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <?php if (hasPermission('catalog.manage') || hasPermission('assets.create')): ?>
        <button type="button" class="btn btn-primary" onclick="openProductDrawer()" style="display: inline-flex; align-items: center; gap: 0.5rem;">
            <i data-lucide="plus" style="width: 18px; height: 18px;"></i>
            <span>Add Product</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Metric Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(18, 177, 176, 0.1); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="layers" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Total Models</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);"><?= number_format($totalProducts) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(59, 130, 246, 0.1); color: #2563eb; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="barcode" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Serialized Models</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);"><?= number_format($serializedProducts) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16, 185, 129, 0.1); color: #059669; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="package-search" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Bulk Inventory Models</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);"><?= number_format($bulkProducts) ?></div>
        </div>
    </div>

    <div class="card" style="padding: 1.25rem; display: flex; align-items: center; gap: 1rem;">
        <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(139, 92, 246, 0.1); color: #7c3aed; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i data-lucide="box" style="width: 24px; height: 24px;"></i>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Total Tracked Units</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary);"><?= number_format($totalUnitsTracked) ?></div>
        </div>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
    <form method="GET" action="<?= BASE_URL ?>/catalog" style="display: grid; grid-template-columns: 2fr 1.5fr 1.5fr 1fr auto; gap: 0.85rem; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Search Catalog</label>
            <div style="position: relative;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-secondary);"></i>
                <input type="text" name="search" class="form-control" placeholder="Brand, model, code..." value="<?= e($filterSearch ?? '') ?>" style="padding-left: 2rem;">
            </div>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Category</label>
            <select name="category_id" class="form-control">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $filterCategory === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?> (<?= e($cat['code']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Tracking Mode</label>
            <select name="tracking_type" class="form-control">
                <option value="">All Tracking Types</option>
                <option value="serialized" <?= $filterTracking === 'serialized' ? 'selected' : '' ?>>Serialized (Individual Units)</option>
                <option value="bulk_quantity" <?= $filterTracking === 'bulk_quantity' ? 'selected' : '' ?>>Bulk Quantity (Stock Ledger)</option>
            </select>
        </div>

        <div class="form-group" style="margin: 0;">
            <label class="form-label" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;">Status</label>
            <select name="is_active" class="form-control">
                <option value="">All Statuses</option>
                <option value="1" <?= $filterStatus === 1 ? 'selected' : '' ?>>Active Only</option>
                <option value="0" <?= $filterStatus === 0 ? 'selected' : '' ?>>Inactive Only</option>
            </select>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <button type="submit" class="btn btn-primary" style="padding: 0.55rem 1rem;">Filter</button>
            <?php if ($filterCategory || $filterTracking || $filterSearch || $filterStatus !== null): ?>
            <a href="<?= BASE_URL ?>/catalog" class="btn btn-outline" style="padding: 0.55rem 0.85rem;" title="Clear Filters">
                <i data-lucide="rotate-ccw" style="width: 16px; height: 16px;"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Catalog Products Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 120px;">Product Code</th>
                    <th>Brand & Model</th>
                    <th>Category</th>
                    <th style="width: 140px;">Tracking Mode</th>
                    <th style="width: 180px;">Inventory Status</th>
                    <th style="width: 140px;">Reference Cost</th>
                    <th style="width: 140px;">Weighted Avg Cost</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 120px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
                        <i data-lucide="boxes" style="width: 40px; height: 40px; margin: 0 auto 0.75rem auto; opacity: 0.4; display: block;"></i>
                        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">No products found</div>
                        <p style="margin: 0; font-size: 0.875rem;">Try adjusting your filters or register a new product.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($products as $p): ?>
                <tr>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary); background: rgba(18, 177, 176, 0.08); padding: 0.2rem 0.45rem; border-radius: 4px; font-size: 0.85rem;">
                            <?= e($p['product_code']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.925rem;">
                            <?= e($p['brand']) ?> <?= e($p['model_name']) ?>
                        </div>
                        <?php if (!empty($p['model_number'])): ?>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            Part / Model #: <span style="font-family: monospace;"><?= e($p['model_number']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($p['specifications'])): ?>
                        <div style="font-size: 0.75rem; color: var(--text-muted); max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= e($p['specifications']) ?>">
                            <?= e($p['specifications']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-size: 0.8rem;">
                            <?= e($p['category_name']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($p['tracking_type'] === 'serialized'): ?>
                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <i data-lucide="barcode" style="width: 12px; height: 12px;"></i> Serialized
                        </span>
                        <?php else: ?>
                        <span class="badge" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.3rem;">
                            <i data-lucide="package" style="width: 12px; height: 12px;"></i> Bulk Stock
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['tracking_type'] === 'serialized'): ?>
                            <a href="<?= BASE_URL ?>/assets?category_id=<?= $p['category_id'] ?>&search=<?= urlencode($p['model_name']) ?>" style="font-weight: 600; color: #2563eb; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                                <span><?= number_format((int)$p['serialized_asset_count']) ?> physical units</span>
                                <i data-lucide="arrow-up-right" style="width: 13px; height: 13px;"></i>
                            </a>
                        <?php else: ?>
                            <?php 
                                $avail = (int)$p['bulk_available_qty'];
                                $assigned = (int)$p['bulk_assigned_qty'];
                                $reorder = (int)$p['min_reorder_level'];
                                $isLow = ($avail <= $reorder);
                            ?>
                            <div style="font-size: 0.85rem;">
                                <span style="font-weight: 700; color: <?= $isLow ? 'var(--danger)' : '#059669' ?>;">
                                    <?= number_format($avail) ?> <?= e($p['unit_of_measure']) ?> avail
                                </span>
                                <span style="color: var(--text-secondary); font-size: 0.775rem;">(<?= number_format($assigned) ?> assigned)</span>
                            </div>
                            <?php if ($isLow && $avail > 0): ?>
                            <span style="font-size: 0.7rem; color: #b45309; background: #fef3c7; padding: 0.1rem 0.35rem; border-radius: 4px; display: inline-block; margin-top: 0.15rem;">
                                Reorder Level (≤<?= $reorder ?>)
                            </span>
                            <?php elseif ($avail === 0): ?>
                            <span style="font-size: 0.7rem; color: #b91c1c; background: #fee2e2; padding: 0.1rem 0.35rem; border-radius: 4px; display: inline-block; margin-top: 0.15rem;">
                                Out of Stock
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.875rem;">
                        <?= $p['default_purchase_cost'] !== null ? formatMoney((float)$p['default_purchase_cost'], DEFAULT_CURRENCY) : '—' ?>
                    </td>
                    <td style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">
                        <?= formatMoney((float)$p['average_unit_cost'], DEFAULT_CURRENCY) ?>
                    </td>
                    <td>
                        <?php if ((int)$p['is_active'] === 1): ?>
                        <span class="badge badge-success" style="font-size: 0.75rem;">Active</span>
                        <?php else: ?>
                        <span class="badge badge-secondary" style="font-size: 0.75rem;">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: inline-flex; align-items: center; gap: 0.4rem;">
                            <?php if ($p['tracking_type'] === 'bulk_quantity'): ?>
                            <a href="<?= BASE_URL ?>/inventory?product_id=<?= $p['id'] ?>" class="btn btn-outline" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" title="View Stock Balances & Ledger">
                                <i data-lucide="boxes" style="width: 14px; height: 14px;"></i>
                            </a>
                            <?php endif; ?>

                            <?php if (hasPermission('catalog.manage') || hasPermission('assets.edit')): ?>
                            <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" onclick="openEditProductDrawer(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" title="Edit Product">
                                <i data-lucide="edit-3" style="width: 14px; height: 14px;"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 1. OFF-CANVAS DRAWER: ADD NEW PRODUCT (50vw width)                    -->
<!-- ====================================================================== -->
<div class="offcanvas-overlay <?= !empty($drawerError) ? 'active' : '' ?>" id="addProductDrawer" onclick="handleDrawerBackdrop(event)">
    <div class="offcanvas-drawer" onclick="event.stopPropagation()">
        <div class="offcanvas-header">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="plus-circle" style="color: var(--primary); width: 22px; height: 22px;"></i>
                    <span>Register New Product Model</span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.825rem; color: var(--text-secondary);">
                    Define master catalog item specifications, tracking type, and reference valuation.
                </p>
            </div>
            <button type="button" class="btn-icon-toggle" onclick="closeProductDrawer()" title="Close Drawer" style="border-radius: 50%; width: 32px; height: 32px; padding: 0;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/catalog" id="addProductForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0;">
            <input type="hidden" name="action" value="create_product">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="offcanvas-body">
                <?php if (!empty($drawerError)): ?>
                <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.6rem;">
                    <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <div><?= e($drawerError) ?></div>
                </div>
                <?php endif; ?>

                <!-- Identification & Category -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="tag" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Classification & Identity</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="add_category_id">Asset Category <span style="color: var(--danger);">*</span></label>
                            <select name="category_id" id="add_category_id" class="form-control" required onchange="handleCategorySelection(this.value)">
                                <option value="">Select Category...</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" data-serialized="<?= $cat['is_serialized'] ?>" <?= (isset($formData['category_id']) && (int)$formData['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?> (<?= $cat['is_serialized'] ? 'Serialized' : 'Bulk/Consumable' ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="add_brand">Brand / Manufacturer <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="brand" id="add_brand" class="form-control" placeholder="e.g. Dell, HP, Logitech, Apple" value="<?= e($formData['brand'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="add_model_name">Model Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="model_name" id="add_model_name" class="form-control" placeholder="e.g. Latitude 5420, M185, UltraSharp 27" value="<?= e($formData['model_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="add_model_number">Model / Part Number</label>
                            <input type="text" name="model_number" id="add_model_number" class="form-control" placeholder="e.g. P137G, 910-002225" value="<?= e($formData['model_number'] ?? '') ?>">
                            <small style="color: var(--text-secondary); font-size: 0.75rem;">Manufacturer part or SKU number</small>
                        </div>
                    </div>
                </div>

                <!-- Tracking & Stock Control -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="sliders" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Tracking Mode & Stock Control</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="add_tracking_type">Tracking Type <span style="color: var(--danger);">*</span></label>
                            <select name="tracking_type" id="add_tracking_type" class="form-control" required>
                                <option value="serialized" <?= (isset($formData['tracking_type']) && $formData['tracking_type'] === 'serialized') ? 'selected' : '' ?>>
                                    Serialized (Individual Physical Assets)
                                </option>
                                <option value="bulk_quantity" <?= (isset($formData['tracking_type']) && $formData['tracking_type'] === 'bulk_quantity') ? 'selected' : '' ?>>
                                    Bulk Quantity (Stock Ledger & Batches)
                                </option>
                            </select>
                            <small style="color: var(--text-secondary); font-size: 0.75rem;">Important: Cannot be modified once stock or units exist.</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="add_unit_of_measure">Unit of Measure</label>
                            <input type="text" name="unit_of_measure" id="add_unit_of_measure" class="form-control" placeholder="units, pcs, boxes, rolls" value="<?= e($formData['unit_of_measure'] ?? 'units') ?>">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="add_min_reorder_level">Minimum Reorder Alert Level</label>
                            <input type="number" name="min_reorder_level" id="add_min_reorder_level" class="form-control" min="0" value="<?= e((string)($formData['min_reorder_level'] ?? 0)) ?>">
                            <small style="color: var(--text-secondary); font-size: 0.75rem;">Alert triggers on dashboard when available bulk stock ≤ this</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="add_default_purchase_cost">Reference Purchase Cost (<?= DEFAULT_CURRENCY ?>)</label>
                            <input type="number" step="0.01" name="default_purchase_cost" id="add_default_purchase_cost" class="form-control" placeholder="0.00" value="<?= e((string)($formData['default_purchase_cost'] ?? '')) ?>">
                        </div>
                    </div>
                </div>

                <!-- Specifications & Metadata -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="file-text" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Specifications & Lifecycle</span>
                    </h4>

                    <div class="form-group">
                        <label class="form-label" for="add_specifications">Technical Specifications / Description</label>
                        <textarea name="specifications" id="add_specifications" class="form-control" rows="3" placeholder="e.g. 16GB RAM, 512GB NVMe SSD, Intel Core i7 11th Gen, FHD IPS display"><?= e($formData['specifications'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group" style="margin-top: 1rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                            <input type="checkbox" name="is_active" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);">Active in Catalog</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer" style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeProductDrawer()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Product</button>
            </div>
        </form>
    </div>
</div>

<!-- ====================================================================== -->
<!-- 2. OFF-CANVAS DRAWER: EDIT PRODUCT (50vw width)                       -->
<!-- ====================================================================== -->
<div class="offcanvas-overlay" id="editProductDrawer" onclick="handleEditDrawerBackdrop(event)">
    <div class="offcanvas-drawer" onclick="event.stopPropagation()">
        <div class="offcanvas-header">
            <div>
                <h3 id="editDrawerTitle" style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="edit" style="color: var(--primary); width: 22px; height: 22px;"></i>
                    <span>Edit Product Model</span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.825rem; color: var(--text-secondary);">
                    Modify catalog item specs, reorder threshold, and reference details.
                </p>
            </div>
            <button type="button" class="btn-icon-toggle" onclick="closeEditProductDrawer()" title="Close Drawer" style="border-radius: 50%; width: 32px; height: 32px; padding: 0;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/catalog" id="editProductForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0;">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="product_id" id="edit_product_id">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="offcanvas-body">
                <div id="trackingLockAlert" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.85rem; align-items: center; gap: 0.6rem;">
                    <i data-lucide="lock" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <div>
                        <strong>Tracking Type Locked:</strong> This product already has registered assets, bulk inventory stock, or assignment records. The tracking mode cannot be mutated.
                    </div>
                </div>

                <!-- Classification & Identity -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="tag" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Classification & Identity</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_category_id">Asset Category <span style="color: var(--danger);">*</span></label>
                            <select name="category_id" id="edit_category_id" class="form-control" required>
                                <option value="">Select Category...</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_brand">Brand / Manufacturer <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="brand" id="edit_brand" class="form-control" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_model_name">Model Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="model_name" id="edit_model_name" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_model_number">Model / Part Number</label>
                            <input type="text" name="model_number" id="edit_model_number" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Tracking & Stock Control -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="sliders" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Tracking Mode & Stock Control</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_tracking_type">Tracking Type</label>
                            <select name="tracking_type" id="edit_tracking_type" class="form-control" required>
                                <option value="serialized">Serialized (Individual Physical Assets)</option>
                                <option value="bulk_quantity">Bulk Quantity (Stock Ledger & Batches)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_unit_of_measure">Unit of Measure</label>
                            <input type="text" name="unit_of_measure" id="edit_unit_of_measure" class="form-control">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_min_reorder_level">Minimum Reorder Alert Level</label>
                            <input type="number" name="min_reorder_level" id="edit_min_reorder_level" class="form-control" min="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_default_purchase_cost">Reference Purchase Cost (<?= DEFAULT_CURRENCY ?>)</label>
                            <input type="number" step="0.01" name="default_purchase_cost" id="edit_default_purchase_cost" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Specifications & Active Status -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="file-text" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Specifications & Status</span>
                    </h4>

                    <div class="form-group">
                        <label class="form-label" for="edit_specifications">Technical Specifications</label>
                        <textarea name="specifications" id="edit_specifications" class="form-control" rows="3"></textarea>
                    </div>

                    <div class="form-group" style="margin-top: 1rem;">
                        <label style="display: inline-flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span style="font-size: 0.9rem; font-weight: 600; color: var(--text-primary);">Active in Catalog</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer" style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeEditProductDrawer()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Product</button>
            </div>
        </form>
    </div>
</div>

<script>
function openProductDrawer() {
    closeEditProductDrawer();
    const drawer = document.getElementById('addProductDrawer');
    if (drawer) {
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            const first = document.getElementById('add_category_id');
            if (first) first.focus();
            if (window.lucide) lucide.createIcons();
        }, 150);
    }
}

function closeProductDrawer() {
    const drawer = document.getElementById('addProductDrawer');
    if (drawer) {
        drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function handleDrawerBackdrop(e) {
    if (e.target.id === 'addProductDrawer') {
        closeProductDrawer();
    }
}

function handleCategorySelection(catId) {
    const select = document.getElementById('add_category_id');
    const selectedOpt = select.options[select.selectedIndex];
    const isSer = selectedOpt ? selectedOpt.getAttribute('data-serialized') : '1';
    const trackingSelect = document.getElementById('add_tracking_type');
    if (trackingSelect) {
        trackingSelect.value = (isSer === '1') ? 'serialized' : 'bulk_quantity';
    }
}

function openEditProductDrawer(data) {
    if (!data) return;
    closeProductDrawer();

    document.getElementById('edit_product_id').value = data.id || '';
    document.getElementById('edit_category_id').value = data.category_id || '';
    document.getElementById('edit_brand').value = data.brand || '';
    document.getElementById('edit_model_name').value = data.model_name || '';
    document.getElementById('edit_model_number').value = data.model_number || '';
    document.getElementById('edit_unit_of_measure').value = data.unit_of_measure || 'units';
    document.getElementById('edit_min_reorder_level').value = data.min_reorder_level || 0;
    document.getElementById('edit_default_purchase_cost').value = data.default_purchase_cost || '';
    document.getElementById('edit_specifications').value = data.specifications || '';
    document.getElementById('edit_is_active').checked = parseInt(data.is_active || 1) === 1;

    const trackingSelect = document.getElementById('edit_tracking_type');
    trackingSelect.value = data.tracking_type || 'serialized';

    const lockAlert = document.getElementById('trackingLockAlert');
    // Lock tracking type if not permitted or has assets/stock
    const canChange = (data.can_change_tracking === undefined) ? false : Boolean(data.can_change_tracking);
    if (!canChange) {
        trackingSelect.setAttribute('disabled', 'disabled');
        if (lockAlert) lockAlert.style.display = 'flex';
    } else {
        trackingSelect.removeAttribute('disabled');
        if (lockAlert) lockAlert.style.display = 'none';
    }

    const titleEl = document.getElementById('editDrawerTitle');
    if (titleEl) {
        titleEl.textContent = 'Edit: ' + (data.brand || '') + ' ' + (data.model_name || '');
    }

    const drawer = document.getElementById('editProductDrawer');
    if (drawer) {
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => {
            if (window.lucide) lucide.createIcons();
            const first = document.getElementById('edit_brand');
            if (first) first.focus();
        }, 150);
    }
}

function closeEditProductDrawer() {
    const drawer = document.getElementById('editProductDrawer');
    if (drawer) {
        drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function handleEditDrawerBackdrop(e) {
    if (e.target.id === 'editProductDrawer') {
        closeEditProductDrawer();
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeProductDrawer();
        closeEditProductDrawer();
    }
});

<?php if (!empty($drawerError)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openProductDrawer();
});
<?php endif; ?>

<?php if (!empty($editProductTarget)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditProductDrawer(<?= json_encode($editProductTarget, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
});
<?php endif; ?>
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
