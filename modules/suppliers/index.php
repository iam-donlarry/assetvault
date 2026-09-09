<?php
// modules/suppliers/index.php - Vendor & Supplier Directory
declare(strict_types=1);

$active_page = 'suppliers';
$page_title = 'Suppliers & Vendors';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('settings.manage');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_supplier') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($name)) {
            $error = 'Supplier Name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO suppliers (name, contact_person, email, phone, address) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $contact ?: null, $email ?: null, $phone ?: null, $address ?: null]);
                flashMessage('success', "Supplier '$name' registered successfully.");
                redirect('suppliers');
            } catch (Exception $e) {
                $error = 'Failed to create supplier: ' . $e->getMessage();
            }
        }
    }
}

$suppliers = $pdo->query("
    SELECT s.*, (SELECT COUNT(*) FROM assets a WHERE a.supplier_id = s.id) AS supplied_assets_count
    FROM suppliers s
    ORDER BY s.name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Suppliers & Service Vendors</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Hardware vendors, authorized warranty providers, and third-party repair partners.
        </p>
    </div>

    <button type="button" class="btn btn-primary" onclick="openSupModal()">
        <i data-lucide="plus-circle"></i>
        <span>Add Supplier</span>
    </button>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container" style="border: none; border-radius: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Contact Person</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Supplied Inventory</th>
                    <th>Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                        No external suppliers or repair vendors registered yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $sup): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--text-primary);"><?= e($sup['name']) ?></td>
                        <td><?= e($sup['contact_person'] ?? '—') ?></td>
                        <td><?= e($sup['email'] ?? '—') ?></td>
                        <td><?= e($sup['phone'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-primary"><?= $sup['supplied_assets_count'] ?> Assets</span>
                        </td>
                        <td style="font-size: 0.825rem; color: var(--text-secondary);"><?= e($sup['address'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="supModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Register Supplier</h3>
            <button type="button" onclick="closeSupModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/suppliers">
            <input type="hidden" name="action" value="create_supplier">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label">Vendor / Supplier Company Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Dell Authorized Partner Nigeria" required>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Person</label>
                <input type="text" name="contact_person" class="form-control" placeholder="Account Manager Name">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="sales@vendor.com">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" placeholder="+234...">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Office Address</label>
                <textarea name="address" class="form-control" rows="2"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeSupModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSupModal() { document.getElementById('supModal').style.display = 'flex'; }
function closeSupModal() { document.getElementById('supModal').style.display = 'none'; }
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
