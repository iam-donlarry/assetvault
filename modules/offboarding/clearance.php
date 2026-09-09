<?php
// modules/offboarding/clearance.php - Interactive Asset Return Inspection & Exit Sign-off
declare(strict_types=1);

$active_page = 'offboarding';
$page_title = 'Asset Return Inspection & Clearance';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('offboarding.view');

$staffId = trim($_GET['staff_id'] ?? '');
if (empty($staffId)) {
    redirect('offboarding');
}

// Fetch Employee & Active Offboarding Request
$stmt = $pdo->prepare("
    SELECT o.*, e.id AS emp_id, e.full_name, e.staff_id, e.job_title, e.email, d.name AS dept_name,
           u.full_name AS cleared_by_name
    FROM offboarding_requests o
    JOIN employees e ON o.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON o.cleared_by_user_id = u.id
    WHERE e.staff_id = ?
    ORDER BY o.id DESC
    LIMIT 1
");
$stmt->execute([$staffId]);
$req = $stmt->fetch();

if (!$req) {
    flashMessage('error', "No offboarding record found for Staff ID '$staffId'.");
    redirect('offboarding');
}

$error = '';

// Handle Individual Item Return Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'inspect_item') {
    requirePermission('offboarding.clear');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $returnStatus = $_POST['return_status'] ?? 'returned_intact';
        $returnedCondition = $_POST['returned_condition'] ?? 'good';
        $damageCost = !empty($_POST['damage_cost_assessed']) ? (float)$_POST['damage_cost_assessed'] : 0.00;
        $damageCurr = $_POST['damage_cost_currency'] ?? 'NGN';
        $notes = trim($_POST['inspection_notes'] ?? '');

        try {
            $pdo->beginTransaction();

            $iStmt = $pdo->prepare("SELECT * FROM offboarding_items WHERE id = ? FOR UPDATE");
            $iStmt->execute([$itemId]);
            $item = $iStmt->fetch();

            if (!$item) {
                throw new Exception("Offboarding item not found.");
            }

            // Update offboarding item
            $updItem = $pdo->prepare("
                UPDATE offboarding_items
                SET return_status = ?,
                    returned_condition = ?,
                    damage_cost_assessed = ?,
                    damage_cost_currency = ?,
                    inspected_by_user_id = ?,
                    inspected_date = CURDATE(),
                    inspection_notes = ?
                WHERE id = ?
            ");
            $updItem->execute([
                $returnStatus,
                $returnedCondition,
                $damageCost,
                $damageCurr,
                $_SESSION['user_id'],
                $notes,
                $itemId
            ]);

            // Close the corresponding active assignment
            $asgId = (int)$item['assignment_id'];
            $assetId = (int)$item['asset_id'];

            $pdo->prepare("
                UPDATE asset_assignments
                SET returned_date = CURDATE(),
                    returned_to_user_id = ?,
                    condition_at_return = ?,
                    status = 'returned',
                    return_reason = 'Staff Offboarding Exit'
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], $returnedCondition, $asgId]);

            // Update asset status
            $nextLifecycle = ($returnStatus === 'lost') ? 'lost' : (($returnedCondition === 'damaged') ? 'damaged' : 'available');
            $pdo->prepare("
                UPDATE assets
                SET lifecycle_status = ?,
                    condition_status = ?,
                    current_holder_id = NULL
                WHERE id = ?
            ")->execute([$nextLifecycle, $returnedCondition, $assetId]);

            // Log movement
            $pdo->prepare("
                INSERT INTO asset_movements (asset_id, to_location_id, from_employee_id, moved_by_user_id, reason, notes)
                VALUES (?, 1, ?, ?, 'Offboarding Return', ?)
            ")->execute([$assetId, $req['emp_id'], $_SESSION['user_id'], "Returned during exit clearance ($returnStatus)"]);

            $pdo->commit();
            flashMessage('success', "Item inspected and logged successfully.");
            redirect("offboarding/clearance/{$staffId}");
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Handle Final Exit Clearance Sign-off
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_off_clearance') {
    requirePermission('offboarding.clear');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        try {
            $pdo->beginTransaction();

            // Check if any items are still pending
            $pendingCount = (int)$pdo->prepare("SELECT COUNT(*) FROM offboarding_items WHERE offboarding_request_id = ? AND return_status = 'pending'")
                ->execute([$req['id']]);
            $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM offboarding_items WHERE offboarding_request_id = {$req['id']} AND return_status = 'pending'")->fetchColumn();

            if ($pendingCount > 0) {
                throw new Exception("Cannot sign off clearance: $pendingCount items have not been inspected or resolved.");
            }

            // Mark Cleared
            $pdo->prepare("
                UPDATE offboarding_requests
                SET clearance_status = 'cleared',
                    cleared_by_user_id = ?,
                    cleared_date = CURDATE()
                WHERE id = ?
            ")->execute([$_SESSION['user_id'], $req['id']]);

            // Mark employee as exited
            $pdo->prepare("UPDATE employees SET employment_status = 'exited', exit_date = CURDATE() WHERE id = ?")
                ->execute([$req['emp_id']]);

            logAudit('offboarding.cleared', 'offboarding_request', (int)$req['id'], ['status' => 'pending'], ['status' => 'cleared']);

            $pdo->commit();
            flashMessage('success', "Employee exit clearance completed and certified successfully!");
            redirect('offboarding');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch items for this offboarding request
$itemsStmt = $pdo->prepare("
    SELECT oi.*, a.asset_tag, a.item_name, a.brand, a.model, a.serial_number, c.name AS category_name,
           u.full_name AS inspector_name
    FROM offboarding_items oi
    JOIN assets a ON oi.asset_id = a.id
    JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN users u ON oi.inspected_by_user_id = u.id
    WHERE oi.offboarding_request_id = ?
    ORDER BY a.item_name ASC
");
$itemsStmt->execute([$req['id']]);
$items = $itemsStmt->fetchAll();

$allResolved = true;
foreach ($items as $it) {
    if ($it['return_status'] === 'pending') {
        $allResolved = false;
        break;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="<?= BASE_URL ?>/offboarding" class="btn btn-outline btn-sm">
            <i data-lucide="arrow-left"></i>
        </a>
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem;">
                <h1 style="font-size: 1.5rem; margin-bottom: 0;">Exit Clearance: <?= e($req['full_name']) ?></h1>
                <span class="badge badge-primary"><?= e($req['request_code']) ?></span>
                <?= getBadgeForStatus($req['clearance_status']) ?>
            </div>
            <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.875rem; margin-top: 0.25rem;">
                <?= e($req['job_title']) ?> — <?= e($req['dept_name']) ?> | Target Exit: <?= formatDate($req['target_exit_date']) ?>
            </p>
        </div>
    </div>

    <div>
        <?php if ($req['clearance_status'] === 'pending'): ?>
            <?php if ($allResolved): ?>
                <form method="POST" action="<?= BASE_URL ?>/offboarding/clearance/<?= urlencode($staffId) ?>" style="display: inline;">
                    <input type="hidden" name="action" value="sign_off_clearance">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Sign off final clearance certificate for this staff member?')">
                        <i data-lucide="shield-check"></i> Sign Off Final Clearance
                    </button>
                </form>
            <?php else: ?>
                <button class="btn btn-outline" disabled title="All items must be inspected first">
                    <i data-lucide="lock"></i> Pending Equipment Verification
                </button>
            <?php endif; ?>
        <?php else: ?>
            <span class="badge badge-success" style="padding: 0.65rem 1.25rem; font-size: 0.9rem;">
                <i data-lucide="check-circle-2"></i> Cleared by <?= e($req['cleared_by_name']) ?> on <?= formatDate($req['cleared_date']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Inspection Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: #ffffff;">
        <h3 style="font-size: 1.1rem; margin-bottom: 0;">Assigned Assets Recovery Checklist</h3>
        <span style="font-size: 0.85rem; color: var(--text-secondary);"><?= count($items) ?> Items to Reconcile</span>
    </div>

    <?php if (empty($items)): ?>
        <div style="padding: 3rem; text-align: center; color: var(--text-secondary);">
            <i data-lucide="check" style="width: 42px; height: 42px; color: #10b981; margin-bottom: 0.5rem;"></i>
            <p>No active company equipment was recorded under this employee.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Asset Tag & Item</th>
                        <th>Category</th>
                        <th>Serial Number</th>
                        <th>Condition (Issued)</th>
                        <th>Inspection Status</th>
                        <th>Condition (Returned)</th>
                        <th>Damage / Loss Fee</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($it['asset_tag']) ?>" style="font-weight: 700; color: var(--primary-color);">
                                <?= e($it['asset_tag']) ?>
                            </a>
                            <div style="font-size: 0.775rem; color: var(--text-secondary);"><?= e($it['item_name']) ?></div>
                        </td>
                        <td><?= e($it['category_name']) ?></td>
                        <td>
                            <?php if ($it['serial_number']): ?>
                                <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem;"><?= e($it['serial_number']) ?></code>
                            <?php else: ?>
                                <span style="color: var(--text-secondary);">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= getBadgeForCondition($it['expected_condition']) ?></td>
                        <td>
                            <?php if ($it['return_status'] === 'pending'): ?>
                                <span class="badge badge-warning">Pending Inspection</span>
                            <?php elseif ($it['return_status'] === 'returned_intact'): ?>
                                <span class="badge badge-success">Returned Intact</span>
                            <?php elseif ($it['return_status'] === 'returned_damaged'): ?>
                                <span class="badge badge-danger">Returned Damaged</span>
                            <?php elseif ($it['return_status'] === 'lost'): ?>
                                <span class="badge badge-danger">Reported Lost</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Waived</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $it['returned_condition'] ? getBadgeForCondition($it['returned_condition']) : '—' ?>
                        </td>
                        <td>
                            <?= ($it['damage_cost_assessed'] > 0) ? formatMoney((float)$it['damage_cost_assessed'], $it['damage_cost_currency']) : '—' ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($it['return_status'] === 'pending' && hasPermission('offboarding.clear')): ?>
                            <button type="button" class="btn btn-primary btn-sm" onclick="openItemModal(<?= htmlspecialchars(json_encode($it)) ?>)">
                                <i data-lucide="clipboard-check"></i> Inspect
                            </button>
                            <?php else: ?>
                            <span style="font-size: 0.75rem; color: var(--text-secondary);">
                                By <?= e($it['inspector_name'] ?? 'Admin') ?>
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Item Inspection Modal -->
<div id="itemModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Equipment Recovery Verification</h3>
            <button type="button" onclick="closeItemModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/offboarding/clearance/<?= urlencode($staffId) ?>">
            <input type="hidden" name="action" value="inspect_item">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="item_id" id="modal_item_id" value="">

            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1.25rem;">
                <div style="font-weight: 700;" id="modal_item_title">AST-001 — Hardware</div>
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="modal_item_serial">Serial: —</div>
            </div>

            <div class="form-group">
                <label class="form-label">Return Disposition *</label>
                <select name="return_status" id="modal_return_status" class="form-control" required onchange="toggleDamageCost(this.value)">
                    <option value="returned_intact" selected>Returned Intact (Acceptable Condition)</option>
                    <option value="returned_damaged">Returned Damaged (Faulty / Physical Damage)</option>
                    <option value="lost">Lost / Missing (Staff Cannot Produce)</option>
                    <option value="waived">Waived / Kept as Gift per Policy</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Condition Evaluated *</label>
                <select name="returned_condition" class="form-control" required>
                    <option value="good" selected>Good Condition</option>
                    <option value="fair">Fair (Normal Wear & Tear)</option>
                    <option value="damaged">Damaged (Requires Repair)</option>
                </select>
            </div>

            <!-- Damage Cost (Shown if damaged or lost) -->
            <div id="damage_cost_row" style="display: none; grid-template-columns: 1fr 2fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Fee Currency</label>
                    <select name="damage_cost_currency" class="form-control">
                        <option value="NGN" selected>NGN (₦)</option>
                        <option value="USD">USD ($)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Damage / Replacement Charge</label>
                    <input type="number" step="0.01" name="damage_cost_assessed" class="form-control" placeholder="0.00">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Inspection Notes</label>
                <textarea name="inspection_notes" class="form-control" rows="2" placeholder="Charger included, screen scratch notes..."></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeItemModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Verification</button>
            </div>
        </form>
    </div>
</div>

<script>
function openItemModal(item) {
    document.getElementById('modal_item_id').value = item.id;
    document.getElementById('modal_item_title').textContent = item.asset_tag + ' — ' + item.item_name;
    document.getElementById('modal_item_serial').textContent = item.serial_number ? ('Serial: ' + item.serial_number) : 'Unserialized item';
    document.getElementById('itemModal').style.display = 'flex';
}
function closeItemModal() {
    document.getElementById('itemModal').style.display = 'none';
}
function toggleDamageCost(status) {
    const row = document.getElementById('damage_cost_row');
    if (status === 'returned_damaged' || status === 'lost') {
        row.style.display = 'grid';
    } else {
        row.style.display = 'none';
    }
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
