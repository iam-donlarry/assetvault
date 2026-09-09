<?php
// modules/offboarding/index.php - Staff Exit Clearance Queue
declare(strict_types=1);

$active_page = 'offboarding';
$page_title = 'Staff Exit Clearance';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('offboarding.view');

$error = '';
$success = '';

// Handle Initiate Offboarding
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'initiate_offboarding') {
    requirePermission('offboarding.manage');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please try again.';
    } else {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $targetExitDate = !empty($_POST['target_exit_date']) ? $_POST['target_exit_date'] : date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($employeeId === 0) {
            $error = 'Please select a valid staff member.';
        } else {
            try {
                $pdo->beginTransaction();

                // Check if already in active offboarding
                $existing = $pdo->prepare("SELECT id FROM offboarding_requests WHERE employee_id = ? AND clearance_status = 'pending'");
                $existing->execute([$employeeId]);
                if ($existing->fetchColumn()) {
                    throw new Exception("An active offboarding request is already pending for this employee.");
                }

                $codeCount = (int)$pdo->query("SELECT COUNT(*) FROM offboarding_requests")->fetchColumn();
                $reqCode = sprintf('OFF-2026-%04d', $codeCount + 1);

                $stmt = $pdo->prepare("
                    INSERT INTO offboarding_requests (
                        request_code, employee_id, initiation_date, target_exit_date, clearance_status,
                        remarks, created_by_user_id
                    ) VALUES (
                        ?, ?, CURDATE(), ?, 'pending', ?, ?
                    )
                ");
                $stmt->execute([
                    $reqCode,
                    $employeeId,
                    $targetExitDate,
                    $remarks ?: null,
                    $_SESSION['user_id']
                ]);
                $offboardingId = (int)$pdo->lastInsertId();

                // Snapshot all currently held assets into offboarding_items
                $asgStmt = $pdo->prepare("
                    SELECT id, asset_id, condition_at_issue 
                    FROM asset_assignments 
                    WHERE employee_id = ? AND status = 'active'
                ");
                $asgStmt->execute([$employeeId]);
                $heldItems = $asgStmt->fetchAll();

                $itemStmt = $pdo->prepare("
                    INSERT INTO offboarding_items (
                        offboarding_request_id, asset_id, assignment_id, expected_condition, return_status
                    ) VALUES (?, ?, ?, ?, 'pending')
                ");

                foreach ($heldItems as $item) {
                    $itemStmt->execute([
                        $offboardingId,
                        $item['asset_id'],
                        $item['id'],
                        $item['condition_at_issue']
                    ]);
                }

                // Update employee employment status to offboarding
                $pdo->prepare("UPDATE employees SET employment_status = 'offboarding' WHERE id = ?")
                    ->execute([$employeeId]);

                logAudit('offboarding.initiate', 'offboarding_request', $offboardingId, null, [
                    'code' => $reqCode,
                    'items_count' => count($heldItems)
                ]);

                $pdo->commit();
                flashMessage('success', "Exit clearance workflow initiated for employee.");
                redirect("offboarding/clearance/{$employeeId}");
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// Fetch Offboarding Requests
$requests = $pdo->query("
    SELECT o.*, e.full_name, e.staff_id, e.job_title, e.email, d.name AS dept_name,
           u.full_name AS cleared_by_name,
           (SELECT COUNT(*) FROM offboarding_items oi WHERE oi.offboarding_request_id = o.id) AS total_items,
           (SELECT COUNT(*) FROM offboarding_items oi WHERE oi.offboarding_request_id = o.id AND oi.return_status != 'pending') AS resolved_items
    FROM offboarding_requests o
    JOIN employees e ON o.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN users u ON o.cleared_by_user_id = u.id
    ORDER BY o.initiation_date DESC
")->fetchAll();

$employees = $pdo->query("SELECT id, full_name, staff_id, job_title FROM employees WHERE employment_status = 'active' ORDER BY full_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Staff Exit Clearance</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Formal asset recovery, condition inspection, damage assessment, and digital exit sign-off.
        </p>
    </div>

    <?php if (hasPermission('offboarding.manage')): ?>
    <button type="button" class="btn btn-primary" onclick="openInitiateModal()">
        <i data-lucide="user-minus"></i>
        <span>Initiate Exit Clearance</span>
    </button>
    <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($requests)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
            <i data-lucide="shield-check" style="width: 48px; height: 48px; color: #10b981; margin-bottom: 0.75rem;"></i>
            <h4>No staff exit clearances pending</h4>
            <p style="font-size: 0.875rem;">All employee assets are reconciled and accounted for.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Clearance Code</th>
                        <th>Exiting Staff</th>
                        <th>Department</th>
                        <th>Target Exit Date</th>
                        <th>Assets Reconciled</th>
                        <th>Clearance Status</th>
                        <th>Signed Off By</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--primary-color);">
                            <?= e($req['request_code']) ?>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($req['staff_id']) ?>" style="font-weight: 600; color: var(--text-primary);">
                                <?= e($req['full_name']) ?>
                            </a>
                            <span style="display: block; font-size: 0.75rem; color: var(--text-secondary);">ID: <?= e($req['staff_id']) ?></span>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?= e($req['dept_name']) ?></span>
                        </td>
                        <td style="font-size: 0.85rem;">
                            <?= formatDate($req['target_exit_date']) ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; width: 80px;">
                                    <?php $pct = $req['total_items'] > 0 ? ($req['resolved_items'] / $req['total_items']) * 100 : 100; ?>
                                    <div style="height: 100%; width: <?= $pct ?>%; background: <?= $pct == 100 ? '#10b981' : '#f59e0b' ?>;"></div>
                                </div>
                                <span style="font-size: 0.775rem; font-weight: 600; color: var(--text-secondary);">
                                    <?= $req['resolved_items'] ?> / <?= $req['total_items'] ?>
                                </span>
                            </div>
                        </td>
                        <td>
                            <?= getBadgeForStatus($req['clearance_status']) ?>
                        </td>
                        <td style="font-size: 0.825rem; color: var(--text-secondary);">
                            <?= e($req['cleared_by_name'] ?? '—') ?>
                        </td>
                        <td style="text-align: right;">
                            <a href="<?= BASE_URL ?>/offboarding/clearance/<?= urlencode($req['staff_id']) ?>" class="btn btn-outline btn-sm">
                                <i data-lucide="clipboard-check"></i> Inspection & Sign-off
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Initiate Offboarding Modal -->
<div id="initModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Initiate Staff Exit Clearance</h3>
            <button type="button" onclick="closeInitiateModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/offboarding">
            <input type="hidden" name="action" value="initiate_offboarding">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="offb_employee_id">Select Exiting Staff Member *</label>
                <select name="employee_id" id="offb_employee_id" class="form-control" required>
                    <option value="">Select Employee...</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= e($emp['full_name']) ?> (ID: <?= e($emp['staff_id']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="target_exit_date">Target Exit Date *</label>
                <input type="date" name="target_exit_date" id="target_exit_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="offb_remarks">Exit Remarks / Handover Context</label>
                <textarea name="remarks" id="offb_remarks" class="form-control" rows="2" placeholder="Resignation, end of contract, transition notes..."></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeInitiateModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Initiate Recovery Workflow</button>
            </div>
        </form>
    </div>
</div>

<script>
function openInitiateModal() {
    document.getElementById('initModal').style.display = 'flex';
}
function closeInitiateModal() {
    document.getElementById('initModal').style.display = 'none';
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
