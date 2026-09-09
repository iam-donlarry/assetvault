<?php
// modules/staff/view.php - Employee Asset Profile & Custody Portfolio
declare(strict_types=1);

$active_page = 'staff';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('staff.view');

$staffId = trim($_GET['staff_id'] ?? '');
if (empty($staffId)) {
    redirect('staff');
}

// Fetch Employee
$stmt = $pdo->prepare("
    SELECT e.*, d.name AS dept_name, m.full_name AS manager_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN employees m ON e.line_manager_id = m.id
    WHERE e.staff_id = ?
    LIMIT 1
");
$stmt->execute([$staffId]);
$emp = $stmt->fetch();

if (!$emp) {
    flashMessage('error', "Employee with Staff ID '$staffId' not found.");
    redirect('staff');
}

$page_title = "Staff Profile: " . $emp['full_name'];

// Fetch Currently Held Assets
$heldStmt = $pdo->prepare("
    SELECT a.*, c.name AS category_name, asg.id AS active_asg_id, asg.assigned_date, asg.condition_at_issue, asg.assignment_code
    FROM assets a
    JOIN asset_categories c ON a.category_id = c.id
    JOIN asset_assignments asg ON asg.asset_id = a.id AND asg.status = 'active'
    WHERE asg.employee_id = ?
    ORDER BY a.item_name ASC
");
$heldStmt->execute([$emp['id']]);
$heldAssets = $heldStmt->fetchAll();

// Fetch Assignment History
$histStmt = $pdo->prepare("
    SELECT asg.*, a.asset_tag, a.item_name, c.name AS category_name
    FROM asset_assignments asg
    JOIN assets a ON asg.asset_id = a.id
    JOIN asset_categories c ON a.category_id = c.id
    WHERE asg.employee_id = ? AND asg.status != 'active'
    ORDER BY asg.returned_date DESC
");
$histStmt->execute([$emp['id']]);
$pastAssets = $histStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 1rem;">
        <a href="<?= BASE_URL ?>/staff" class="btn btn-outline btn-sm" style="padding: 0.4rem 0.6rem;">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <h1 style="font-size: 1.6rem; margin-bottom: 0;"><?= e($emp['full_name']) ?></h1>
                <span class="badge badge-primary">ID: <?= e($emp['staff_id']) ?></span>
                <?php if ($emp['m365_assigned']): ?>
                    <span class="badge badge-success">M365 Active</span>
                <?php endif; ?>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.875rem; margin-top: 0.25rem;">
                <?= e($emp['job_title']) ?> — <?= e($emp['dept_name']) ?> | <?= e($emp['email']) ?><?= !empty($emp['phone']) ? ' | ' . e($emp['phone']) : '' ?>
            </p>
        </div>
    </div>

    <div style="display: flex; gap: 0.65rem;">
        <?php if (hasPermission('staff.manage')): ?>
        <a href="<?= BASE_URL ?>/staff?edit=<?= urlencode($emp['staff_id']) ?>" class="btn btn-outline">
            <i data-lucide="edit-3"></i> Edit Staff
        </a>
        <?php endif; ?>

        <?php if (hasPermission('assets.assign')): ?>
        <a href="<?= BASE_URL ?>/assignments/checkout?staff_id=<?= $emp['id'] ?>" class="btn btn-primary">
            <i data-lucide="plus-circle"></i> Issue Equipment
        </a>
        <?php endif; ?>

        <?php if (hasPermission('offboarding.manage')): ?>
        <a href="<?= BASE_URL ?>/offboarding?initiate_staff_id=<?= $emp['id'] ?>" class="btn btn-outline" style="border-color: #fca5a5; color: #dc2626;">
            <i data-lucide="user-minus"></i> Exit Clearance
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Header Card -->
<div class="card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem;">
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Department</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-top: 0.2rem;"><?= e($emp['dept_name']) ?></div>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Line Manager</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-top: 0.2rem;"><?= e($emp['manager_name'] ?? '—') ?></div>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">M365 Account</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--text-primary); margin-top: 0.2rem;">
                <?= $emp['m365_assigned'] ? 'Provisioned (' . e($emp['m365_license_type'] ?: 'Business Standard') . ')' : 'Not Assigned' ?>
            </div>
        </div>
        <div>
            <div style="font-size: 0.8rem; color: var(--text-secondary); text-transform: uppercase;">Currently Assigned</div>
            <div style="font-size: 1rem; font-weight: 700; color: var(--primary-color); margin-top: 0.2rem;">
                <?= count($heldAssets) ?> Equipment Items
            </div>
        </div>
    </div>
</div>

<!-- Currently Held Assets Table -->
<div class="card" style="padding: 0; overflow: hidden; margin-bottom: 1.5rem;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #ffffff;">
        <h3 style="font-size: 1.1rem; margin-bottom: 0;">Currently Assigned Equipment</h3>
        <span style="font-size: 0.825rem; font-weight: 600; color: var(--primary-color);"><?= count($heldAssets) ?> Items Active</span>
    </div>

    <?php if (empty($heldAssets)): ?>
        <div style="text-align: center; padding: 3rem 1rem; color: var(--text-secondary);">
            <i data-lucide="package-open" style="width: 42px; height: 42px; opacity: 0.4; margin-bottom: 0.5rem;"></i>
            <p style="margin-bottom: 0.5rem;">No active equipment currently assigned to this employee.</p>
            <a href="<?= BASE_URL ?>/assignments/checkout?staff_id=<?= $emp['id'] ?>" class="btn btn-primary btn-sm">Issue First Asset</a>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset Tag</th>
                        <th>Item / Description</th>
                        <th>Category</th>
                        <th>Serial Number</th>
                        <th>Issued Date</th>
                        <th>Condition (Issue)</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($heldAssets as $h): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($h['asset_tag']) ?>" style="font-weight: 700; color: var(--primary-color);">
                                <?= e($h['asset_tag']) ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight: 600; color: var(--text-primary);"><?= e($h['item_name']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= e($h['brand'] ?? '—') ?> <?= e($h['model'] ? '(' . $h['model'] . ')' : '') ?></div>
                        </td>
                        <td><?= e($h['category_name']) ?></td>
                        <td>
                            <?php if ($h['serial_number']): ?>
                                <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem;"><?= e($h['serial_number']) ?></code>
                            <?php else: ?>
                                <span style="color: var(--text-secondary);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.85rem;"><?= formatDate($h['assigned_date']) ?></td>
                        <td><?= getBadgeForCondition($h['condition_at_issue']) ?></td>
                        <td style="text-align: right;">
                            <a href="<?= BASE_URL ?>/assignments?return_asset_id=<?= $h['id'] ?>" class="btn btn-outline btn-sm">
                                <i data-lucide="arrow-down-left"></i> Return
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Past Assignment History -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between;">
        <h3 style="font-size: 1.1rem; margin-bottom: 0;">Returned Equipment History</h3>
        <span style="font-size: 0.825rem; color: var(--text-secondary);"><?= count($pastAssets) ?> Items Returned</span>
    </div>

    <?php if (empty($pastAssets)): ?>
        <div style="padding: 2.5rem; text-align: center; color: var(--text-secondary); font-size: 0.875rem;">
            No historical equipment returns recorded for this staff member.
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Equipment</th>
                        <th>Category</th>
                        <th>Assigned Date</th>
                        <th>Returned Date</th>
                        <th>Return Condition</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pastAssets as $p): ?>
                    <tr>
                        <td style="font-weight: 600; font-size: 0.85rem;"><?= e($p['assignment_code']) ?></td>
                        <td style="font-weight: 600;"><?= e($p['asset_tag']) ?> — <?= e($p['item_name']) ?></td>
                        <td><?= e($p['category_name']) ?></td>
                        <td style="font-size: 0.85rem;"><?= formatDate($p['assigned_date']) ?></td>
                        <td style="font-size: 0.85rem;"><?= formatDate($p['returned_date']) ?></td>
                        <td><?= $p['condition_at_return'] ? getBadgeForCondition($p['condition_at_return']) : '—' ?></td>
                        <td style="font-size: 0.85rem; color: var(--text-secondary);"><?= e($p['return_reason'] ?? '—') ?></td>
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
