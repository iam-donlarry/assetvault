<?php
// modules/settings/categories.php - Asset Categories Management
declare(strict_types=1);

$active_page = 'categories';
$page_title = 'Asset Categories';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('settings.manage');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_category') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $isSerialized = isset($_POST['is_serialized']) ? 1 : 0;
        $interval = !empty($_POST['maintenance_interval']) ? (int)$_POST['maintenance_interval'] : null;
        $desc = trim($_POST['description'] ?? '');

        if (empty($name) || empty($code)) {
            $error = 'Category Name and Code are required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO asset_categories (code, name, is_serialized, default_maintenance_interval_days, description)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$code, $name, $isSerialized, $interval, $desc ?: null]);
                flashMessage('success', "Category '$name' created successfully.");
                redirect('categories');
            } catch (Exception $e) {
                $error = 'Failed to create category: ' . $e->getMessage();
            }
        }
    }
}

$categories = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id AND a.lifecycle_status != 'disposed') AS active_count
    FROM asset_categories c
    ORDER BY c.name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Asset Categories</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Hardware classification, serialization requirements, and default preventative servicing cycles.
        </p>
    </div>

    <button type="button" class="btn btn-primary" onclick="openCatModal()">
        <i data-lucide="plus-circle"></i>
        <span>Add Category</span>
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
                    <th>Category Name</th>
                    <th>Prefix Code</th>
                    <th>Tracking Model</th>
                    <th>Default Servicing Cycle</th>
                    <th>Active Inventory</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td style="font-weight: 700; color: var(--text-primary);">
                        <?= e($cat['name']) ?>
                    </td>
                    <td>
                        <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 700; color: var(--primary-color);">
                            <?= e($cat['code']) ?>
                        </code>
                    </td>
                    <td>
                        <?php if ($cat['is_serialized']): ?>
                            <span class="badge badge-primary">
                                <i data-lucide="barcode" style="width: 12px; height: 12px;"></i> Serialized (Unique)
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary">
                                <i data-lucide="layers" style="width: 12px; height: 12px;"></i> Bulk Consumable
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                        <?= $cat['default_maintenance_interval_days'] ? "Every {$cat['default_maintenance_interval_days']} days" : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $cat['active_count'] > 0 ? 'badge-success' : 'badge-secondary' ?>">
                            <?= $cat['active_count'] ?> Assets
                        </span>
                    </td>
                    <td style="font-size: 0.825rem; color: var(--text-secondary);">
                        <?= e($cat['description'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Category Modal -->
<div id="catModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Add Asset Category</h3>
            <button type="button" onclick="closeCatModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/categories">
            <input type="hidden" name="action" value="create_category">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="cat_name">Category Name *</label>
                    <input type="text" name="name" id="cat_name" class="form-control" placeholder="e.g. Projector" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="cat_code">Code (2-4 chars) *</label>
                    <input type="text" name="code" id="cat_code" class="form-control" placeholder="PRJ" maxlength="10" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="cat_interval">Default Servicing Interval (Days)</label>
                <input type="number" name="maintenance_interval" id="cat_interval" class="form-control" placeholder="180">
            </div>

            <div class="form-group">
                <label class="form-label" for="cat_desc">Description</label>
                <textarea name="description" id="cat_desc" class="form-control" rows="2"></textarea>
            </div>

            <div style="margin-bottom: 1.5rem; padding: 0.75rem; background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm);">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.875rem;">
                    <input type="checkbox" name="is_serialized" value="1" checked style="accent-color: var(--primary-color);">
                    <span>Requires mandatory unique serial number (Serialized Equipment)</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeCatModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Category</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCatModal() { document.getElementById('catModal').style.display = 'flex'; }
function closeCatModal() { document.getElementById('catModal').style.display = 'none'; }
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
