<?php
// modules/assets/view.php - 360° Comprehensive Asset Profile
declare(strict_types=1);

$active_page = 'assets';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('assets.view');

$tag = trim($_GET['tag'] ?? '');
if (empty($tag)) {
    redirect('assets');
}

// Fetch Asset Record
$stmt = $pdo->prepare("
    SELECT a.*, c.name AS category_name, c.is_serialized,
           l.name AS location_name, l.building, l.floor,
           p.product_code, p.model_name AS prod_model_name, p.model_number AS prod_model_number, p.specifications AS prod_specifications, p.average_unit_cost,
           s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone,
           e.full_name AS custodian_name, e.staff_id AS custodian_staff_id, e.email AS custodian_email, e.job_title AS custodian_title
    FROM assets a
    JOIN asset_categories c ON a.category_id = c.id
    JOIN locations l ON a.current_location_id = l.id
    LEFT JOIN product_models p ON a.product_id = p.id
    LEFT JOIN suppliers s ON a.supplier_id = s.id
    LEFT JOIN employees e ON a.current_holder_id = e.id
    WHERE a.asset_tag = ?
    LIMIT 1
");
$stmt->execute([$tag]);
$asset = $stmt->fetch();

if (!$asset) {
    flashMessage('error', "Asset with tag '$tag' not found.");
    redirect('assets');
}

$page_title = "Asset Profile: " . $asset['asset_tag'];

// Fetch Assignment History
$asgStmt = $pdo->prepare("
    SELECT asg.*, e.full_name AS staff_name, e.staff_id, u.full_name AS assigned_by_name
    FROM asset_assignments asg
    JOIN employees e ON asg.employee_id = e.id
    LEFT JOIN users u ON asg.assigned_by_user_id = u.id
    WHERE asg.asset_id = ?
    ORDER BY asg.id DESC
");
$asgStmt->execute([$asset['id']]);
$assignments = $asgStmt->fetchAll();

// Fetch Movements History
$movStmt = $pdo->prepare("
    SELECT m.*, fl.name AS from_loc_name, tl.name AS to_loc_name, 
           fe.full_name AS from_emp_name, te.full_name AS to_emp_name,
           u.full_name AS moved_by_name
    FROM asset_movements m
    LEFT JOIN locations fl ON m.from_location_id = fl.id
    JOIN locations tl ON m.to_location_id = tl.id
    LEFT JOIN employees fe ON m.from_employee_id = fe.id
    LEFT JOIN employees te ON m.to_employee_id = te.id
    LEFT JOIN users u ON m.moved_by_user_id = u.id
    WHERE m.asset_id = ?
    ORDER BY m.id DESC
");
$movStmt->execute([$asset['id']]);
$movements = $movStmt->fetchAll();

// Fetch Maintenance Logs
$maintStmt = $pdo->prepare("
    SELECT m.*, u.full_name AS created_by_name
    FROM asset_maintenance m
    LEFT JOIN users u ON m.created_by_user_id = u.id
    WHERE m.asset_id = ?
    ORDER BY m.scheduled_date DESC
");
$maintStmt->execute([$asset['id']]);
$maintenanceLogs = $maintStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <a href="<?= BASE_URL ?>/assets" class="btn btn-outline btn-sm" style="padding: 0.4rem 0.6rem;">
                <i data-lucide="arrow-left"></i>
            </a>
            <h1 style="font-size: 1.6rem; margin-bottom: 0;"><?= e($asset['item_name']) ?></h1>
            <?= getBadgeForStatus($asset['lifecycle_status']) ?>
            <?= getBadgeForCondition($asset['condition_status']) ?>
        </div>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.875rem; margin-top: 0.35rem;">
            Tag: <strong style="color: var(--primary-color);"><?= e($asset['asset_tag']) ?></strong> 
            <?php if ($asset['serial_number']): ?>
                | Serial: <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?= e($asset['serial_number']) ?></code>
            <?php endif; ?>
            | Category: <?= e($asset['category_name']) ?>
        </p>
    </div>

    <div style="display: flex; gap: 0.65rem;">
        <?php if ($asset['lifecycle_status'] === 'available' && hasPermission('assets.assign')): ?>
        <a href="<?= BASE_URL ?>/assignments/checkout?asset_id=<?= $asset['id'] ?>" class="btn btn-primary">
            <i data-lucide="user-plus"></i> Issue to Staff
        </a>
        <?php endif; ?>

        <?php if ($asset['lifecycle_status'] === 'assigned' && hasPermission('assets.return')): ?>
        <a href="<?= BASE_URL ?>/assignments?return_asset_id=<?= $asset['id'] ?>" class="btn btn-danger">
            <i data-lucide="arrow-down-left"></i> Process Return
        </a>
        <?php endif; ?>

        <?php if (hasPermission('maintenance.create')): ?>
        <a href="<?= BASE_URL ?>/maintenance?log_asset_id=<?= $asset['id'] ?>" class="btn btn-outline">
            <i data-lucide="wrench"></i> Log Maintenance
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- 3-Column Profile Grid -->
<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
    <!-- Card 1: Identification & Hardware Specs -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="font-size: 1rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
            Identification & Hardware
        </h3>
        
        <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary); width: 45%;">Asset Tag</td>
                <td style="padding: 0.5rem 0; font-weight: 700; color: var(--primary-color);"><?= e($asset['asset_tag']) ?></td>
            </tr>
            <?php if (!empty($asset['legacy_asset_id'])): ?>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Legacy Asset ID</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= e($asset['legacy_asset_id']) ?></td>
            </tr>
            <?php endif; ?>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Category</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= e($asset['category_name']) ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Brand / Model</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= e($asset['brand'] ?? '—') ?> <?= e($asset['model'] ? '(' . $asset['model'] . ')' : '') ?></td>
            </tr>
            <?php if (!empty($asset['product_code'])): ?>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Catalog Master</td>
                <td style="padding: 0.5rem 0;">
                    <a href="<?= BASE_URL ?>/catalog?search=<?= urlencode($asset['product_code']) ?>" style="font-family: monospace; font-weight: 700; color: var(--primary); text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem;">
                        <span><?= e($asset['product_code']) ?></span>
                        <i data-lucide="external-link" style="width: 12px; height: 12px;"></i>
                    </a>
                </td>
            </tr>
            <?php endif; ?>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Serial Number</td>
                <td style="padding: 0.5rem 0;">
                    <?php if ($asset['serial_number']): ?>
                        <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 600;"><?= e($asset['serial_number']) ?></code>
                    <?php else: ?>
                        <span style="color: var(--text-secondary); font-style: italic;">Unserialized bulk item</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Quantity</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= $asset['quantity'] ?></td>
            </tr>
        </table>
    </div>

    <!-- Card 2: Current Custody & Room Location -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="font-size: 1rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
            Current Custody & Room
        </h3>

        <div style="margin-bottom: 1.25rem;">
            <span style="font-size: 0.8rem; color: var(--text-secondary); display: block; margin-bottom: 0.25rem;">Assigned Staff Custodian</span>
            <?php if (!empty($asset['custodian_name'])): ?>
                <div style="display: flex; align-items: center; gap: 0.65rem;">
                    <div class="user-avatar-placeholder" style="width: 38px; height: 38px; font-size: 0.9rem; font-weight: 700;">
                        <?= strtoupper(substr($asset['custodian_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($asset['custodian_staff_id']) ?>" style="font-weight: 700; color: var(--text-primary); text-decoration: none;">
                            <?= e($asset['custodian_name']) ?>
                        </a>
                        <div style="font-size: 0.775rem; color: var(--text-secondary);">
                            ID: <?= e($asset['custodian_staff_id']) ?> | <?= e($asset['custodian_title']) ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div style="padding: 0.5rem 0; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                    <i data-lucide="package-check" style="width: 18px; height: 18px;"></i>
                    <span>In Inventory / Store (Not Assigned)</span>
                </div>
            <?php endif; ?>
        </div>

        <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary); width: 45%;">Room Location</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= e($asset['location_name']) ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Building / Floor</td>
                <td style="padding: 0.5rem 0;"><?= e($asset['building']) ?> (<?= e($asset['floor']) ?>)</td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Current Condition</td>
                <td style="padding: 0.5rem 0;"><?= getBadgeForCondition($asset['condition_status']) ?></td>
            </tr>
        </table>
    </div>

    <!-- Card 3: Dual Currency & Financials -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="font-size: 1rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">
            Dual Currency & Warranty
        </h3>

        <table style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary); width: 45%;">Purchase Cost</td>
                <td style="padding: 0.5rem 0; font-weight: 700; color: var(--text-primary);">
                    <?= formatDualCurrency((float)$asset['purchase_cost'], $asset['purchase_currency'] ?? 'NGN', (float)$asset['secondary_cost'], $asset['secondary_currency'] ?? 'USD') ?>
                </td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Purchase Date</td>
                <td style="padding: 0.5rem 0;"><?= formatDate($asset['purchase_date']) ?></td>
            </tr>
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Warranty Expiry</td>
                <td style="padding: 0.5rem 0;">
                    <?php if ($asset['warranty_expiry_date']): 
                        $isExpired = strtotime($asset['warranty_expiry_date']) < time();
                    ?>
                        <span style="color: <?= $isExpired ? '#ef4444' : '#10b981' ?>; font-weight: 600;">
                            <?= formatDate($asset['warranty_expiry_date']) ?> <?= $isExpired ? '(Expired)' : '(Active)' ?>
                        </span>
                    <?php else: ?>
                        <span style="color: var(--text-secondary);">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td style="padding: 0.5rem 0; color: var(--text-secondary);">Supplier / Vendor</td>
                <td style="padding: 0.5rem 0; font-weight: 600;"><?= e($asset['supplier_name'] ?? '—') ?></td>
            </tr>
        </table>
    </div>
</div>

<!-- Tabs Container: Custody History & Movement Ledger -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
        <h3 style="font-size: 1.1rem; margin-bottom: 0;">Chain of Custody & Assignment History</h3>
        <span style="font-size: 0.8rem; color: var(--text-secondary);"><?= count($assignments) ?> Recorded Checkouts</span>
    </div>

    <?php if (empty($assignments)): ?>
        <div style="padding: 2.5rem; text-align: center; color: var(--text-secondary);">
            <p style="margin-bottom: 0;">No historical assignments found for this asset.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Assignment Code</th>
                        <th>Staff Recipient</th>
                        <th>Assigned Date</th>
                        <th>Condition Issued</th>
                        <th>Returned Date</th>
                        <th>Condition Returned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $asg): ?>
                    <tr>
                        <td style="font-weight: 600;"><?= e($asg['assignment_code']) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($asg['staff_id']) ?>" style="font-weight: 600; color: var(--text-primary);">
                                <?= e($asg['staff_name']) ?>
                            </a>
                            <span style="display: block; font-size: 0.75rem; color: var(--text-secondary);">ID: <?= e($asg['staff_id']) ?></span>
                        </td>
                        <td style="font-size: 0.85rem;"><?= formatDate($asg['assigned_date']) ?></td>
                        <td><?= getBadgeForCondition($asg['condition_at_issue']) ?></td>
                        <td style="font-size: 0.85rem;"><?= formatDate($asg['returned_date']) ?></td>
                        <td><?= $asg['condition_at_return'] ? getBadgeForCondition($asg['condition_at_return']) : '—' ?></td>
                        <td>
                            <span class="badge <?= $asg['status'] === 'active' ? 'badge-info' : 'badge-secondary' ?>">
                                <?= ucfirst($asg['status']) ?>
                            </span>
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
