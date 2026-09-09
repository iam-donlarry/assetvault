<?php
// modules/locations/index.php - Office Location & Store Directory
declare(strict_types=1);

$active_page = 'locations';
$page_title = 'Office Locations';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('settings.manage');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_location') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security token.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $building = trim($_POST['building'] ?? 'Main Office');
        $floor = trim($_POST['floor'] ?? 'Ground Floor');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name) || empty($code)) {
            $error = 'Location Name and Code are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO locations (code, name, building, floor, description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$code, $name, $building, $floor, $desc ?: null]);
                flashMessage('success', "Location '$name' added successfully.");
                redirect('locations');
            } catch (Exception $e) {
                $error = 'Failed to create location: ' . $e->getMessage();
            }
        }
    }
}

$locations = $pdo->query("
    SELECT l.*, (SELECT COUNT(*) FROM assets a WHERE a.current_location_id = l.id AND a.lifecycle_status != 'disposed') AS assets_count
    FROM locations l
    ORDER BY l.building ASC, l.floor ASC, l.name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Office Locations & Stores</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Physical rooms, secure inventory store rooms, and departments across company facilities.
        </p>
    </div>

    <button type="button" class="btn btn-primary" onclick="openLocModal()">
        <i data-lucide="plus-circle"></i>
        <span>Add Location</span>
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
                    <th>Location Name</th>
                    <th>Code</th>
                    <th>Building</th>
                    <th>Floor</th>
                    <th>Assets Stationed</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td style="font-weight: 700; color: var(--text-primary);">
                        <?= e($loc['name']) ?>
                    </td>
                    <td>
                        <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 700; color: var(--primary-color);">
                            <?= e($loc['code']) ?>
                        </code>
                    </td>
                    <td><?= e($loc['building']) ?></td>
                    <td><?= e($loc['floor']) ?></td>
                    <td>
                        <span class="badge <?= $loc['assets_count'] > 0 ? 'badge-primary' : 'badge-secondary' ?>">
                            <?= $loc['assets_count'] ?> Assets
                        </span>
                    </td>
                    <td style="font-size: 0.825rem; color: var(--text-secondary);">
                        <?= e($loc['description'] ?? '—') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="locModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Add Office Location</h3>
            <button type="button" onclick="closeLocModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/locations">
            <input type="hidden" name="action" value="create_location">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label">Location / Room Name *</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Finance Archive Room" required>
            </div>

            <div class="form-group">
                <label class="form-label">Location Code *</label>
                <input type="text" name="code" class="form-control" placeholder="LOC-FIN-ARCH" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Building</label>
                    <input type="text" name="building" class="form-control" value="Main Office">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Floor</label>
                    <input type="text" name="floor" class="form-control" value="Ground Floor">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeLocModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Location</button>
            </div>
        </form>
    </div>
</div>

<script>
function openLocModal() { document.getElementById('locModal').style.display = 'flex'; }
function closeLocModal() { document.getElementById('locModal').style.display = 'none'; }
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
