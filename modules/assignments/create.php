<?php
// modules/assignments/create.php - Dual Checkout (Serialized Units & Bulk Inventory)
declare(strict_types=1);

$active_page = 'assignments_checkout';
$page_title = 'Issue Equipment / Checkout';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('assets.assign');

$assignmentManager = new AssignmentManager($pdo);
$error = '';
$preselectedAssetId = !empty($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
$preselectedStaffId = !empty($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;

// Fetch Available Serialized Assets
$assets = $pdo->query("
    SELECT a.*, c.name AS category_name, l.name AS location_name, p.product_code
    FROM assets a
    JOIN asset_categories c ON a.category_id = c.id
    JOIN locations l ON a.current_location_id = l.id
    LEFT JOIN product_models p ON a.product_id = p.id
    WHERE a.lifecycle_status = 'available'
    ORDER BY a.item_name ASC
")->fetchAll();

// Fetch Bulk Products and their available location stocks
$bulkStockRows = $pdo->query("
    SELECT s.product_id, s.location_id, s.quantity_available,
           p.brand, p.model_name, p.product_code, p.unit_of_measure,
           l.name AS location_name, l.building, l.floor
    FROM inventory_stock s
    JOIN product_models p ON s.product_id = p.id
    JOIN locations l ON s.location_id = l.id
    WHERE s.quantity_available > 0 AND p.tracking_type = 'bulk_quantity'
    ORDER BY p.brand ASC, p.model_name ASC, l.name ASC
")->fetchAll();

// Fetch Active Employees
$employees = $pdo->query("
    SELECT e.*, d.name AS dept_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.employment_status = 'active'
    ORDER BY e.full_name ASC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please try again.';
    } else {
        $checkoutType = $_POST['checkout_type'] ?? 'serialized';
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $conditionAtIssue = $_POST['condition_at_issue'] ?? 'good';
        $assignedDate = !empty($_POST['assigned_date']) ? $_POST['assigned_date'] : date('Y-m-d');
        $agreementSigned = isset($_POST['agreement_signed']) ? 1 : 0;
        $remarks = trim($_POST['remarks'] ?? '');

        if ($employeeId === 0) {
            $error = 'Recipient employee must be selected.';
        } elseif ($checkoutType === 'serialized') {
            $assetId = (int)($_POST['asset_id'] ?? 0);
            if ($assetId === 0) {
                $error = 'Please select an available serialized asset.';
            } else {
                try {
                    $asgId = $assignmentManager->checkoutSerialized(
                        $assetId,
                        $employeeId,
                        $assignedDate,
                        (int)$_SESSION['user_id'],
                        [
                            'condition'        => $conditionAtIssue,
                            'agreement_signed' => $agreementSigned,
                            'remarks'          => $remarks
                        ]
                    );

                    logAudit('assignment.checkout_serialized', 'asset_assignments', $asgId, null, [
                        'asset_id' => $assetId,
                        'employee_id' => $employeeId
                    ]);

                    flashMessage('success', "Serialized equipment successfully issued to staff.");
                    redirect('assignments');
                } catch (Exception $e) {
                    $error = 'Checkout failed: ' . $e->getMessage();
                }
            }
        } elseif ($checkoutType === 'bulk') {
            $stockKey = $_POST['bulk_stock_source'] ?? '';
            $quantity = (int)($_POST['bulk_quantity'] ?? 0);

            if (empty($stockKey) || strpos($stockKey, '_') === false) {
                $error = 'Please select a bulk product and source stock location.';
            } elseif ($quantity <= 0) {
                $error = 'Checkout quantity must be at least 1 unit.';
            } else {
                [$productId, $sourceLocationId] = explode('_', $stockKey);
                $productId = (int)$productId;
                $sourceLocationId = (int)$sourceLocationId;

                try {
                    $asgId = $assignmentManager->checkoutBulk(
                        $productId,
                        $sourceLocationId,
                        $quantity,
                        $employeeId,
                        $assignedDate,
                        (int)$_SESSION['user_id'],
                        [
                            'condition' => $conditionAtIssue,
                            'remarks'   => $remarks
                        ]
                    );

                    logAudit('assignment.checkout_bulk', 'asset_assignments', $asgId, null, [
                        'product_id' => $productId,
                        'source_location_id' => $sourceLocationId,
                        'quantity' => $quantity,
                        'employee_id' => $employeeId
                    ]);

                    flashMessage('success', "Successfully checked out {$quantity} bulk units to staff.");
                    redirect('assignments');
                } catch (Exception $e) {
                    $error = 'Checkout failed: ' . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 860px; margin: 0 auto;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="arrow-up-right" style="color: var(--primary); width: 26px; height: 26px;"></i>
                <span>Issue Equipment / Staff Checkout</span>
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0;">Check out serialized hardware or bulk peripherals to an employee with custody tracking.</p>
        </div>
        <a href="<?= BASE_URL ?>/assignments" class="btn btn-outline btn-sm">
            <i data-lucide="arrow-left"></i> Back to Custody
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
            <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/assignments/checkout" class="card" style="padding: 2rem;">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

        <!-- Mode Selector: Serialized vs Bulk -->
        <div style="margin-bottom: 1.75rem;">
            <label class="form-label" style="font-weight: 700; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em; color: var(--text-secondary);">
                Select Issuance Category
            </label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <label style="border: 2px solid var(--primary); border-radius: var(--radius-sm); padding: 1rem; cursor: pointer; display: flex; align-items: flex-start; gap: 0.75rem; background: rgba(18, 177, 176, 0.03);" id="label_opt_serialized">
                    <input type="radio" name="checkout_type" value="serialized" checked onchange="toggleCheckoutType('serialized')" style="accent-color: var(--primary); margin-top: 3px;">
                    <div>
                        <div style="font-weight: 700; color: var(--text-primary); margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.4rem;">
                            <i data-lucide="barcode" style="width: 16px; height: 16px; color: var(--primary);"></i>
                            <span>Serialized Equipment Unit</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">Laptops, monitors, smartphones, printers, or discrete numbered hardware units (Qty: 1).</div>
                    </div>
                </label>

                <label style="border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1rem; cursor: pointer; display: flex; align-items: flex-start; gap: 0.75rem;" id="label_opt_bulk">
                    <input type="radio" name="checkout_type" value="bulk" onchange="toggleCheckoutType('bulk')" style="accent-color: var(--primary); margin-top: 3px;">
                    <div>
                        <div style="font-weight: 700; color: var(--text-primary); margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.4rem;">
                            <i data-lucide="package" style="width: 16px; height: 16px; color: var(--primary);"></i>
                            <span>Bulk Inventory / Consumable</span>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary);">Mice, keyboards, headsets, adaptors, ID badges issued from room stock (Qty: 1+).</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Section 1A: Serialized Unit Selection -->
        <div id="section_serialized" style="margin-bottom: 1.5rem;">
            <div class="form-group">
                <label class="form-label" for="asset_id">Select Serialized Physical Asset <span style="color: var(--danger);">*</span></label>
                <select name="asset_id" id="asset_id" class="form-control">
                    <option value="">-- Choose Available Asset --</option>
                    <?php foreach ($assets as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $preselectedAssetId === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= e($a['asset_tag']) ?> — <?= e($a['item_name']) ?> (SN: <?= e($a['serial_number'] ?? 'N/A') ?>) @ <?= e($a['location_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($assets)): ?>
                <small style="color: var(--danger); font-size: 0.8rem; display: block; margin-top: 0.35rem;">
                    No serialized assets are currently marked as available. Register or check in assets first.
                </small>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section 1B: Bulk Product & Location Stock Selection -->
        <div id="section_bulk" style="display: none; margin-bottom: 1.5rem;">
            <div class="form-group">
                <label class="form-label" for="bulk_stock_source">Select Product & Available Stock Location <span style="color: var(--danger);">*</span></label>
                <select name="bulk_stock_source" id="bulk_stock_source" class="form-control" onchange="handleBulkStockSelect(this)">
                    <option value="">-- Choose Product & Room Stock --</option>
                    <?php foreach ($bulkStockRows as $bs): ?>
                    <option value="<?= $bs['product_id'] ?>_<?= $bs['location_id'] ?>" data-max="<?= $bs['quantity_available'] ?>" data-uom="<?= e($bs['unit_of_measure']) ?>">
                        <?= e($bs['brand']) ?> <?= e($bs['model_name']) ?> — <?= e($bs['location_name']) ?> (<?= $bs['quantity_available'] ?> <?= e($bs['unit_of_measure']) ?> available)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($bulkStockRows)): ?>
                <small style="color: var(--danger); font-size: 0.8rem; display: block; margin-top: 0.35rem;">
                    No bulk inventory stock available. Go to <a href="<?= BASE_URL ?>/inventory" style="color: var(--primary);">Bulk Inventory Intake</a> to receive stock.
                </small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label" for="bulk_quantity">Quantity to Issue <span style="color: var(--danger);">*</span></label>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <input type="number" name="bulk_quantity" id="bulk_quantity" class="form-control" min="1" value="1" style="max-width: 160px;">
                    <span id="bulk_max_hint" style="color: var(--text-secondary); font-size: 0.85rem;"></span>
                </div>
            </div>
        </div>

        <!-- Section 2: Employee Assignment Details -->
        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary); display: flex; align-items: center; gap: 0.45rem;">
            <i data-lucide="user" style="width: 18px; height: 18px;"></i>
            <span>Recipient Staff & Custody Terms</span>
        </h3>

        <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label" for="employee_id">Assign To Employee <span style="color: var(--danger);">*</span></label>
                <select name="employee_id" id="employee_id" class="form-control" required>
                    <option value="">Select Employee...</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $preselectedStaffId === (int)$emp['id'] ? 'selected' : '' ?>>
                        <?= e($emp['full_name']) ?> (<?= e($emp['staff_id']) ?> — <?= e($emp['dept_name'] ?? 'General') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="assigned_date">Checkout / Assignment Date</label>
                <input type="date" name="assigned_date" id="assigned_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label" for="condition_at_issue">Condition at Issuance</label>
                <select name="condition_at_issue" id="condition_at_issue" class="form-control">
                    <option value="new">New (Factory Sealed)</option>
                    <option value="good" selected>Good (Operational / Clean)</option>
                    <option value="fair">Fair (Working with Cosmetic Wear)</option>
                </select>
            </div>

            <div class="form-group" id="agreement_box" style="display: flex; align-items: center; margin-top: 1.75rem;">
                <label style="display: inline-flex; align-items: center; gap: 0.6rem; cursor: pointer;">
                    <input type="checkbox" name="agreement_signed" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
                    <span style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">Custody handover agreement acknowledged</span>
                </label>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label class="form-label" for="remarks">Assignment Remarks / Project Allocation</label>
            <textarea name="remarks" id="remarks" class="form-control" rows="2" placeholder="Optional context, e.g. Issued for Q3 remote client engagement"></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 1rem; border-top: 1px solid var(--border); padding-top: 1.25rem;">
            <a href="<?= BASE_URL ?>/assignments" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="check"></i> Confirm Equipment Checkout
            </button>
        </div>
    </form>
</div>

<script>
function toggleCheckoutType(type) {
    const secSer = document.getElementById('section_serialized');
    const secBulk = document.getElementById('section_bulk');
    const lblSer = document.getElementById('label_opt_serialized');
    const lblBulk = document.getElementById('label_opt_bulk');
    const assetSelect = document.getElementById('asset_id');
    const bulkSelect = document.getElementById('bulk_stock_source');

    if (type === 'serialized') {
        secSer.style.display = 'block';
        secBulk.style.display = 'none';
        lblSer.style.borderColor = 'var(--primary)';
        lblSer.style.background = 'rgba(18, 177, 176, 0.03)';
        lblBulk.style.borderColor = 'var(--border)';
        lblBulk.style.background = 'transparent';
        if (assetSelect) assetSelect.setAttribute('required', 'required');
        if (bulkSelect) bulkSelect.removeAttribute('required');
    } else {
        secSer.style.display = 'none';
        secBulk.style.display = 'block';
        lblBulk.style.borderColor = 'var(--primary)';
        lblBulk.style.background = 'rgba(18, 177, 176, 0.03)';
        lblSer.style.borderColor = 'var(--border)';
        lblSer.style.background = 'transparent';
        if (assetSelect) assetSelect.removeAttribute('required');
        if (bulkSelect) bulkSelect.setAttribute('required', 'required');
    }
}

function handleBulkStockSelect(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) return;

    const max = parseInt(opt.getAttribute('data-max') || '1');
    const uom = opt.getAttribute('data-uom') || 'units';

    const qtyInput = document.getElementById('bulk_quantity');
    const hint = document.getElementById('bulk_max_hint');

    if (qtyInput) {
        qtyInput.max = max;
        if (parseInt(qtyInput.value) > max) qtyInput.value = max;
    }
    if (hint) {
        hint.textContent = `(Max available: ${max} ${uom})`;
    }
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
