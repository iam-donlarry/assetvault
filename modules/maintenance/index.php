<?php
// modules/maintenance/index.php - Asset Maintenance & Repair Hub
declare(strict_types=1);

$active_page = 'maintenance';
$page_title = 'Maintenance & Repairs';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('maintenance.view');

$error = '';
$success = '';

// Handle New Maintenance Log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_maintenance') {
    requirePermission('maintenance.create');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please try again.';
    } else {
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $type = $_POST['maintenance_type'] ?? 'preventative';
        $scheduledDate = $_POST['scheduled_date'] ?? date('Y-m-d');
        $performedBy = $_POST['performed_by_type'] ?? 'internal';
        $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $techName = trim($_POST['technician_name'] ?? '');
        
        // Dual Currency Cost
        $currency = trim($_POST['currency'] ?? 'NGN');
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0.00;
        $secondaryCurr = trim($_POST['secondary_currency'] ?? 'USD');
        $secondaryCost = !empty($_POST['secondary_cost']) ? (float)$_POST['secondary_cost'] : null;

        $issueDesc = trim($_POST['issue_description'] ?? '');
        $setRepairStatus = isset($_POST['set_repair_status']) ? 1 : 0;
        $nextIntervalDays = !empty($_POST['next_interval_days']) ? (int)$_POST['next_interval_days'] : 180;

        if ($assetId === 0) {
            $error = 'Please select a valid equipment record.';
        } else {
            try {
                $pdo->beginTransaction();

                $codeCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_maintenance")->fetchColumn();
                $maintCode = sprintf('MNT-2026-%04d', $codeCount + 1);

                $stmt = $pdo->prepare("
                    INSERT INTO asset_maintenance (
                        maintenance_code, asset_id, maintenance_type, scheduled_date, performed_by_type,
                        supplier_id, technician_name, currency, cost, secondary_currency, secondary_cost,
                        status, issue_description, created_by_user_id
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        'scheduled', ?, ?
                    )
                ");
                $stmt->execute([
                    $maintCode, $assetId, $type, $scheduledDate, $performedBy,
                    $supplierId, $techName, $currency, $cost, $secondaryCurr, $secondaryCost,
                    $issueDesc, $_SESSION['user_id']
                ]);

                // Update asset next maintenance date
                $nextDate = date('Y-m-d', strtotime("+$nextIntervalDays days", strtotime($scheduledDate)));
                if ($setRepairStatus) {
                    $pdo->prepare("UPDATE assets SET lifecycle_status = 'in_repair', next_maintenance_date = ? WHERE id = ?")
                        ->execute([$nextDate, $assetId]);
                } else {
                    $pdo->prepare("UPDATE assets SET next_maintenance_date = ? WHERE id = ?")
                        ->execute([$nextDate, $assetId]);
                }

                logAudit('maintenance.create', 'asset_maintenance', (int)$pdo->lastInsertId(), null, [
                    'asset_id' => $assetId,
                    'code' => $maintCode,
                    'type' => $type
                ]);

                $pdo->commit();
                flashMessage('success', "Maintenance ticket {$maintCode} scheduled successfully!");
                redirect('maintenance');
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// Complete Maintenance Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_maintenance') {
    requirePermission('maintenance.update');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        $maintId = (int)($_POST['maintenance_id'] ?? 0);
        $actionsTaken = trim($_POST['actions_taken'] ?? '');

        try {
            $pdo->beginTransaction();

            $mStmt = $pdo->prepare("SELECT * FROM asset_maintenance WHERE id = ? FOR UPDATE");
            $mStmt->execute([$maintId]);
            $mRecord = $mStmt->fetch();

            if ($mRecord) {
                $upd = $pdo->prepare("
                    UPDATE asset_maintenance
                    SET status = 'completed', completed_date = CURDATE(), actions_taken = ?
                    WHERE id = ?
                ");
                $upd->execute([$actionsTaken, $maintId]);

                // Revert asset to available if it was in repair
                $pdo->prepare("
                    UPDATE assets 
                    SET lifecycle_status = CASE WHEN lifecycle_status = 'in_repair' THEN 'available' ELSE lifecycle_status END,
                        last_maintenance_date = CURDATE()
                    WHERE id = ?
                ")->execute([$mRecord['asset_id']]);

                logAudit('maintenance.complete', 'asset_maintenance', $maintId, ['status' => 'scheduled'], ['status' => 'completed']);
            }

            $pdo->commit();
            flashMessage('success', "Maintenance ticket completed successfully.");
            redirect('maintenance');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch Maintenance Records
$logs = $pdo->query("
    SELECT m.*, a.asset_tag, a.item_name, c.name AS category_name, s.name AS supplier_name
    FROM asset_maintenance m
    JOIN assets a ON m.asset_id = a.id
    JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN suppliers s ON m.supplier_id = s.id
    ORDER BY m.scheduled_date DESC
")->fetchAll();

$allAssets = $pdo->query("SELECT id, asset_tag, item_name, serial_number FROM assets WHERE lifecycle_status != 'disposed' ORDER BY item_name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Maintenance & Repairs</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Preventative servicing, repair tickets, downtime monitoring, and dual-currency cost tracking.
        </p>
    </div>

    <?php if (hasPermission('maintenance.create')): ?>
    <button type="button" class="btn btn-primary" onclick="openLogModal()">
        <i data-lucide="plus-circle"></i>
        <span>Schedule Service / Repair</span>
    </button>
    <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($logs)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
            <i data-lucide="wrench" style="width: 48px; height: 48px; opacity: 0.4; margin-bottom: 0.75rem;"></i>
            <h4>No maintenance tickets logged yet</h4>
            <p style="font-size: 0.875rem;">Schedule routine maintenance or log repairs using the button above.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ticket Code</th>
                        <th>Equipment</th>
                        <th>Type</th>
                        <th>Scheduled Date</th>
                        <th>Service Provider</th>
                        <th>Cost (Dual Currency)</th>
                        <th>Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--primary-color);">
                            <?= e($log['maintenance_code']) ?>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($log['asset_tag']) ?>" style="font-weight: 600; color: var(--text-primary);">
                                <?= e($log['asset_tag']) ?> — <?= e($log['item_name']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?= ucfirst($log['maintenance_type']) ?></span>
                        </td>
                        <td style="font-size: 0.85rem;">
                            <?= formatDate($log['scheduled_date']) ?>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--text-secondary);">
                            <?= e($log['supplier_name'] ?? ($log['technician_name'] ?: 'Internal IT')) ?>
                        </td>
                        <td style="font-weight: 600; font-size: 0.85rem;">
                            <?= formatDualCurrency((float)$log['cost'], $log['currency'], (float)$log['secondary_cost'], $log['secondary_currency'] ?? 'USD') ?>
                        </td>
                        <td>
                            <span class="badge <?= $log['status'] === 'completed' ? 'badge-success' : 'badge-warning' ?>">
                                <?= ucfirst(str_replace('_', ' ', $log['status'])) ?>
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <?php if ($log['status'] !== 'completed' && hasPermission('maintenance.update')): ?>
                            <button class="btn btn-outline btn-sm" onclick="openCompleteModal(<?= htmlspecialchars(json_encode($log)) ?>)">
                                <i data-lucide="check"></i> Complete
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Schedule Service Modal -->
<div id="logModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 650px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Schedule Service or Repair</h3>
            <button type="button" onclick="closeLogModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/maintenance">
            <input type="hidden" name="action" value="log_maintenance">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="m_asset_id">Equipment *</label>
                <select name="asset_id" id="m_asset_id" class="form-control" required>
                    <option value="">Select Equipment...</option>
                    <?php foreach ($allAssets as $ast): ?>
                    <option value="<?= $ast['id'] ?>">[<?= e($ast['asset_tag']) ?>] <?= e($ast['item_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="m_type">Maintenance Type *</label>
                    <select name="maintenance_type" id="m_type" class="form-control" required>
                        <option value="preventative" selected>Preventative Servicing</option>
                        <option value="corrective">Corrective Repair</option>
                        <option value="upgrade">Hardware Upgrade</option>
                        <option value="inspection">Safety / Tag Inspection</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="m_scheduled_date">Scheduled Date *</label>
                    <input type="date" name="scheduled_date" id="m_scheduled_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="m_performed_by">Service Provider Type</label>
                    <select name="performed_by_type" id="m_performed_by" class="form-control">
                        <option value="internal" selected>Internal IT Team</option>
                        <option value="vendor">External Vendor</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="m_supplier_id">Vendor / Supplier</label>
                    <select name="supplier_id" id="m_supplier_id" class="form-control">
                        <option value="">None / Internal</option>
                        <?php foreach ($suppliers as $sup): ?>
                        <option value="<?= $sup['id'] ?>"><?= e($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Dual Currency Maintenance Cost -->
            <div style="display: grid; grid-template-columns: 1fr 1.5fr 1fr 1.5fr; gap: 0.75rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Currency</label>
                    <select name="currency" class="form-control">
                        <option value="NGN" selected>NGN (₦)</option>
                        <option value="USD">USD ($)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Cost</label>
                    <input type="number" step="0.01" name="cost" class="form-control" placeholder="0.00">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Secondary</label>
                    <select name="secondary_currency" class="form-control">
                        <option value="USD" selected>USD ($)</option>
                        <option value="NGN">NGN (₦)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Cost</label>
                    <input type="number" step="0.01" name="secondary_cost" class="form-control" placeholder="0.00">
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="m_issue_desc">Issue Description / Servicing Scope</label>
                <textarea name="issue_description" id="m_issue_desc" class="form-control" rows="2" placeholder="Describe symptoms, parts to replace, or preventative checks..."></textarea>
            </div>

            <div style="margin-bottom: 1.5rem; padding: 0.75rem; background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm);">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem;">
                    <input type="checkbox" name="set_repair_status" value="1" style="accent-color: var(--primary-color);">
                    <span>Flag asset status as <strong>In Repair</strong> (removes from active pool)</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeLogModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Schedule Ticket</button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Ticket Modal -->
<div id="completeModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Sign Off Maintenance</h3>
            <button type="button" onclick="closeCompleteModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/maintenance">
            <input type="hidden" name="action" value="complete_maintenance">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="maintenance_id" id="complete_maint_id" value="">

            <div class="form-group">
                <label class="form-label" for="complete_actions">Actions Taken & Results *</label>
                <textarea name="actions_taken" id="complete_actions" class="form-control" rows="3" placeholder="Parts replaced, diagnostics test results, firmware updated..." required></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeCompleteModal()">Cancel</button>
                <button type="submit" class="btn btn-success">Mark Completed</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLogModal() {
    document.getElementById('logModal').style.display = 'flex';
}
function closeLogModal() {
    document.getElementById('logModal').style.display = 'none';
}
function openCompleteModal(log) {
    document.getElementById('complete_maint_id').value = log.id;
    document.getElementById('completeModal').style.display = 'flex';
}
function closeCompleteModal() {
    document.getElementById('completeModal').style.display = 'none';
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
