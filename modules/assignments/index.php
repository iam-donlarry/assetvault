<?php
// modules/assignments/index.php - Asset & Bulk Inventory Assignment Registry & Returns
declare(strict_types=1);

$active_page = 'assignments';
$page_title = 'Asset & Inventory Assignments';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('assets.view');

$assignmentManager = new AssignmentManager($pdo);
$error = '';

// Handle Equipment Return Action (Serialized or Bulk, Full or Partial)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_asset') {
    requirePermission('assets.return');

    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please try again.';
    } else {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $returnedQuantity = max(1, (int)($_POST['returned_quantity'] ?? 1));
        $conditionAtReturn = $_POST['condition_at_return'] ?? 'good';
        $returnLocationId = (int)($_POST['return_location_id'] ?? 1);
        $returnReason = trim($_POST['return_reason'] ?? 'Routine Return');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($assignmentId === 0) {
            $error = 'Invalid assignment record selected.';
        } else {
            try {
                $assignmentManager->returnItem(
                    $assignmentId,
                    $returnedQuantity,
                    $returnLocationId,
                    $conditionAtReturn,
                    date('Y-m-d'),
                    (int)$_SESSION['user_id'],
                    $returnReason,
                    $remarks
                );

                logAudit('assignment.return', 'asset_assignments', $assignmentId, null, [
                    'returned_quantity' => $returnedQuantity,
                    'condition_at_return' => $conditionAtReturn,
                    'return_location_id' => $returnLocationId
                ]);

                flashMessage('success', "Equipment return processed successfully.");
                redirect('assignments');
            } catch (Exception $e) {
                $error = 'Return processing error: ' . $e->getMessage();
            }
        }
    }
}

// Filter parameters
$statusFilter = $_GET['status'] ?? 'active';
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];

if ($statusFilter === 'active') {
    $where[] = "asg.status IN ('active', 'partially_returned')";
} elseif ($statusFilter === 'returned') {
    $where[] = "asg.status = 'returned'";
}

if ($search !== '') {
    $where[] = "(a.asset_tag LIKE :s OR a.item_name LIKE :s OR p.product_code LIKE :s OR p.model_name LIKE :s OR e.full_name LIKE :s OR e.staff_id LIKE :s OR asg.assignment_code LIKE :s)";
    $params[':s'] = "%$search%";
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT asg.*, 
           a.asset_tag, a.item_name AS asset_item_name, a.brand AS asset_brand, a.model AS asset_model, a.serial_number,
           p.product_code, p.brand AS prod_brand, p.model_name AS prod_model_name, p.tracking_type, p.unit_of_measure,
           COALESCE(c_asset.name, c_prod.name) AS category_name,
           src_loc.name AS source_location_name,
           e.full_name AS staff_name, e.staff_id, e.email AS staff_email,
           u.full_name AS assigned_by_name
    FROM asset_assignments asg
    LEFT JOIN assets a ON asg.asset_id = a.id
    LEFT JOIN asset_categories c_asset ON a.category_id = c_asset.id
    LEFT JOIN product_models p ON asg.product_id = p.id
    LEFT JOIN asset_categories c_prod ON p.category_id = c_prod.id
    LEFT JOIN locations src_loc ON asg.source_location_id = src_loc.id
    JOIN employees e ON asg.employee_id = e.id
    LEFT JOIN users u ON asg.assigned_by_user_id = u.id
    $whereSql
    ORDER BY asg.id DESC
");
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$locations = $pdo->query("SELECT id, name, floor FROM locations ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="arrow-left-right" style="color: var(--primary); width: 28px; height: 28px;"></i>
            <span>Asset & Inventory Assignments</span>
        </h1>
        <p style="margin: 0.25rem 0 0; color: var(--text-secondary); font-size: 0.875rem;">
            Track active employee custody, partial bulk returns, and return condition inspections.
        </p>
    </div>

    <?php if (hasPermission('assets.assign')): ?>
    <a href="<?= BASE_URL ?>/assignments/checkout" class="btn btn-primary">
        <i data-lucide="arrow-up-right"></i>
        <span>Issue Equipment</span>
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
        <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
        <span><?= e($error) ?></span>
    </div>
<?php endif; ?>

<!-- Filter Tabs & Search -->
<div class="card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; gap: 0.5rem;">
            <a href="<?= BASE_URL ?>/assignments?status=active<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="btn <?= $statusFilter === 'active' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                Active & Partial Checkouts
            </a>
            <a href="<?= BASE_URL ?>/assignments?status=returned<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="btn <?= $statusFilter === 'returned' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                Returned History
            </a>
            <a href="<?= BASE_URL ?>/assignments?status=all<?= $search ? '&q='.urlencode($search) : '' ?>" 
               class="btn <?= $statusFilter === 'all' ? 'btn-primary' : 'btn-outline' ?> btn-sm">
                All Records
            </a>
        </div>

        <form method="GET" action="<?= BASE_URL ?>/assignments" style="display: flex; gap: 0.5rem; width: 100%; max-width: 380px;">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <div style="position: relative; flex: 1;">
                <i data-lucide="search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: var(--text-secondary);"></i>
                <input type="text" name="q" class="form-control" placeholder="Search staff, code, serial..." value="<?= e($search) ?>" style="padding-left: 2rem;">
            </div>
            <button type="submit" class="btn btn-outline" style="padding: 0.55rem 0.85rem;">
                <i data-lucide="search" style="width: 16px; height: 16px;"></i>
            </button>
        </form>
    </div>
</div>

<!-- Assignments Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 140px;">Assignment Code</th>
                    <th>Equipment / Model</th>
                    <th style="width: 130px;">Tracking Type</th>
                    <th>Employee (Custodian)</th>
                    <th style="width: 120px; text-align: center;">Qty (Out/Ret)</th>
                    <th style="width: 110px;">Assigned Date</th>
                    <th style="width: 130px;">Status</th>
                    <th style="width: 110px; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 3.5rem 1rem; color: var(--text-secondary);">
                        <i data-lucide="inbox" style="width: 44px; height: 44px; margin: 0 auto 0.75rem auto; opacity: 0.4; display: block;"></i>
                        <div style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">No assignment records found</div>
                        <p style="margin: 0; font-size: 0.875rem;">Issue equipment to staff using the button above.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($assignments as $asg): 
                    $isSerialized = !empty($asg['asset_id']);
                    $displayName = $isSerialized 
                        ? ($asg['asset_item_name'] ?: ($asg['asset_brand'] . ' ' . $asg['asset_model']))
                        : ($asg['prod_brand'] . ' ' . $asg['prod_model_name']);
                    $remaining = (int)$asg['quantity'] - (int)$asg['quantity_returned'];
                ?>
                <tr>
                    <td>
                        <span style="font-family: monospace; font-weight: 700; color: var(--primary); font-size: 0.85rem;">
                            <?= e($asg['assignment_code']) ?>
                        </span>
                    </td>
                    <td>
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.925rem;">
                            <?= e($displayName) ?>
                        </div>
                        <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <?php if ($isSerialized): ?>
                                <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($asg['asset_tag']) ?>" style="font-weight: 600; color: #2563eb; text-decoration: none;">
                                    Tag: <?= e($asg['asset_tag']) ?>
                                </a>
                                <?php if (!empty($asg['serial_number'])): ?>
                                <span>• SN: <code style="font-size: 0.75rem; background: #f1f5f9; padding: 1px 4px;"><?= e($asg['serial_number']) ?></code></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>Code: <strong><?= e($asg['product_code']) ?></strong></span>
                                <?php if (!empty($asg['source_location_name'])): ?>
                                <span>• Source: <?= e($asg['source_location_name']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($isSerialized): ?>
                        <span class="badge" style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 0.75rem;">
                            <i data-lucide="barcode" style="width: 11px; height: 11px; vertical-align: middle;"></i> Serialized
                        </span>
                        <?php else: ?>
                        <span class="badge" style="background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 0.75rem;">
                            <i data-lucide="package" style="width: 11px; height: 11px; vertical-align: middle;"></i> Bulk Stock
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($asg['staff_id']) ?>" style="font-weight: 600; color: var(--text-primary); text-decoration: none;">
                            <?= e($asg['staff_name']) ?>
                        </a>
                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                            ID: <?= e($asg['staff_id']) ?>
                        </div>
                    </td>
                    <td style="text-align: center;">
                        <span style="font-weight: 700; font-size: 0.95rem; color: var(--text-primary);">
                            <?= (int)$asg['quantity'] ?>
                        </span>
                        <?php if ((int)$asg['quantity_returned'] > 0): ?>
                        <div style="font-size: 0.725rem; color: #059669; font-weight: 600;">
                            (<?= (int)$asg['quantity_returned'] ?> returned)
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                        <?= formatDate($asg['assigned_date']) ?>
                    </td>
                    <td>
                        <?php if ($asg['status'] === 'active'): ?>
                            <span class="badge badge-success" style="font-size: 0.75rem;">Active Custody</span>
                        <?php elseif ($asg['status'] === 'partially_returned'): ?>
                            <span class="badge badge-warning" style="font-size: 0.75rem; background: #fef3c7; color: #b45309; border: 1px solid #fde68a;">
                                Partial (<?= $remaining ?> remaining)
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size: 0.75rem;">Fully Returned</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if (in_array($asg['status'], ['active', 'partially_returned'], true) && hasPermission('assets.return')): ?>
                        <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; color: var(--danger); border-color: #fecaca;" 
                                onclick="openReturnModal(<?= htmlspecialchars(json_encode([
                                    'id'            => $asg['id'],
                                    'is_serialized' => $isSerialized,
                                    'title'         => $displayName,
                                    'staff'         => $asg['staff_name'] . ' (' . $asg['staff_id'] . ')',
                                    'total_qty'     => (int)$asg['quantity'],
                                    'returned_qty'  => (int)$asg['quantity_returned'],
                                    'remaining'     => $remaining,
                                    'uom'           => $asg['unit_of_measure'] ?? 'units'
                                ]), ENT_QUOTES, 'UTF-8') ?>)">
                            <i data-lucide="rotate-ccw" style="width: 13px; height: 13px;"></i>
                            <span>Return</span>
                        </button>
                        <?php elseif ($isSerialized && !empty($asg['asset_tag'])): ?>
                        <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($asg['asset_tag']) ?>" class="btn btn-outline" style="padding: 0.35rem 0.6rem; font-size: 0.75rem;" title="View Unit Details">
                            <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ====================================================================== -->
<!-- RETURN ASSET / STOCK MODAL OVERLAY                                    -->
<!-- ====================================================================== -->
<div id="returnModal" class="modal-overlay" onclick="closeReturnModal()" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="card" onclick="event.stopPropagation()" style="width: 100%; max-width: 520px; padding: 1.5rem; margin: 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="rotate-ccw" style="color: var(--primary); width: 20px; height: 20px;"></i>
                <span>Process Equipment Return</span>
            </h3>
            <button type="button" onclick="closeReturnModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-secondary);">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/assignments">
            <input type="hidden" name="action" value="return_asset">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="assignment_id" id="modal_asg_id" value="">

            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 0.85rem; margin-bottom: 1.25rem;">
                <div style="font-weight: 700; font-size: 0.925rem;" id="modal_item_title">Equipment Name</div>
                <div style="font-size: 0.8rem; color: var(--text-secondary);" id="modal_staff_title">Held by: Employee Name</div>
                <div style="font-size: 0.8rem; color: #059669; font-weight: 600; margin-top: 0.25rem;" id="modal_remaining_badge"></div>
            </div>

            <!-- Dynamic Quantity for Bulk Partial Returns -->
            <div class="form-group" id="modal_qty_container" style="display: none;">
                <label class="form-label" for="modal_returned_qty">Quantity Being Returned Now <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <input type="number" name="returned_quantity" id="modal_returned_qty" class="form-control" min="1" value="1" style="max-width: 140px;">
                    <span id="modal_uom_label" style="font-size: 0.85rem; color: var(--text-secondary);"></span>
                </div>
                <small style="color: var(--text-secondary); font-size: 0.75rem;">You may return all or a portion of the staff member's checked out items.</small>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_condition">Physical Condition at Return <span style="color: var(--danger);">*</span></label>
                <select name="condition_at_return" id="modal_condition" class="form-control" required>
                    <option value="good" selected>Good — Operational, clean, normal wear</option>
                    <option value="fair">Fair — Cosmetic wear, fully functional</option>
                    <option value="damaged">Damaged — Faulty, requires repair / write-down</option>
                </select>
                <small style="color: var(--text-secondary); font-size: 0.75rem;">Damaged items are automatically routed to damaged stock / status.</small>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_location">Return To Room / Store <span style="color: var(--danger);">*</span></label>
                <select name="return_location_id" id="modal_location" class="form-control" required>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= strtolower($loc['name']) === 'store' ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?> (<?= e($loc['floor']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_reason">Reason for Return <span style="color: var(--danger);">*</span></label>
                <select name="return_reason" id="modal_reason" class="form-control" required>
                    <option value="Routine Return">Routine Return</option>
                    <option value="Hardware Upgrade">Hardware Upgrade</option>
                    <option value="Department Transfer">Department Transfer</option>
                    <option value="Offboarding / Exit">Offboarding / Exit</option>
                    <option value="Damaged / Faulty">Damaged / Faulty</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="modal_remarks">Inspection Remarks</label>
                <textarea name="remarks" id="modal_remarks" class="form-control" rows="2" placeholder="Accessories verified, inspection notes..."></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeReturnModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Return</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReturnModal(data) {
    if (!data) return;

    document.getElementById('modal_asg_id').value = data.id;
    document.getElementById('modal_item_title').textContent = data.title;
    document.getElementById('modal_staff_title').textContent = 'Currently held by: ' + data.staff;

    const qtyContainer = document.getElementById('modal_qty_container');
    const qtyInput = document.getElementById('modal_returned_qty');
    const uomLabel = document.getElementById('modal_uom_label');
    const remainingBadge = document.getElementById('modal_remaining_badge');

    if (!data.is_serialized) {
        qtyContainer.style.display = 'block';
        qtyInput.max = data.remaining;
        qtyInput.value = data.remaining; // default to returning all remaining
        uomLabel.textContent = `units of ${data.remaining} ${data.uom} remaining`;
        remainingBadge.textContent = `Total Assigned: ${data.total_qty}, Already Returned: ${data.returned_qty}, Remaining: ${data.remaining} ${data.uom}`;
    } else {
        qtyContainer.style.display = 'none';
        qtyInput.value = 1;
        qtyInput.max = 1;
        remainingBadge.textContent = 'Individual Serialized Physical Unit (1 unit)';
    }

    const modal = document.getElementById('returnModal');
    if (modal) {
        modal.style.display = 'flex';
        if (window.lucide) lucide.createIcons();
    }
}

function closeReturnModal() {
    const modal = document.getElementById('returnModal');
    if (modal) modal.style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReturnModal();
    }
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
