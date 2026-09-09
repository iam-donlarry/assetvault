<?php
// modules/settings/users.php - User Accounts & Role Management
declare(strict_types=1);

$active_page = 'users';
$page_title = 'User Accounts & Roles';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('users.manage');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $fullName = trim($_POST['full_name'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 2);
        $employeeId = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'Username, email, and password are required.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, role_id, employee_id, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$username, $email, $hash, $fullName, $roleId, $employeeId]);
                flashMessage('success', "User account '$username' created successfully.");
                redirect('users');
            } catch (Exception $e) {
                $error = 'Failed to create user: ' . $e->getMessage();
            }
        }
    }
}

$users = $pdo->query("
    SELECT u.*, r.name AS role_name, r.slug AS role_slug, e.staff_id, e.job_title
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN employees e ON u.employee_id = e.id
    ORDER BY u.id ASC
")->fetchAll();

$roles = $pdo->query("SELECT id, name, slug, description FROM roles ORDER BY id ASC")->fetchAll();
$employees = $pdo->query("SELECT id, full_name, staff_id FROM employees ORDER BY full_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">User Accounts & Access Control</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Manage internal system users, login credentials, and role-based permissions.
        </p>
    </div>

    <button type="button" class="btn btn-primary" onclick="openUserModal()">
        <i data-lucide="user-plus"></i>
        <span>Create User</span>
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
                    <th>User & Name</th>
                    <th>Email Address</th>
                    <th>Role</th>
                    <th>Linked Staff Record</th>
                    <th>Status</th>
                    <th>Last Active</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div style="font-weight: 700; color: var(--text-primary);"><?= e($u['full_name']) ?></div>
                        <div style="font-size: 0.775rem; color: var(--text-secondary);">@<?= e($u['username']) ?></div>
                    </td>
                    <td style="font-size: 0.85rem;"><?= e($u['email']) ?></td>
                    <td>
                        <span class="badge <?= $u['role_slug'] === 'super_admin' ? 'badge-primary' : 'badge-secondary' ?>">
                            <?= e($u['role_name']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['staff_id']): ?>
                            <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($u['staff_id']) ?>" style="font-weight: 600; font-size: 0.825rem;">
                                Staff ID: <?= e($u['staff_id']) ?>
                            </a>
                        <?php else: ?>
                            <span style="color: var(--text-secondary); font-size: 0.825rem;">Standalone Admin</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Disabled' ?>
                        </span>
                    </td>
                    <td style="font-size: 0.825rem; color: var(--text-secondary);">
                        <?= $u['last_login'] ? timeAgo($u['last_login']) : 'Never' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="userModal" class="modal-overlay">
    <div class="modal-content">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
            <h3 style="font-size: 1.15rem; margin-bottom: 0;">Create User Account</h3>
            <button type="button" onclick="closeUserModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer;">&times;</button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/users">
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="full_name" class="form-control" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Email Address *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Role *</label>
                    <select name="role_id" class="form-control" required>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Link to Staff Member</label>
                    <select name="employee_id" class="form-control">
                        <option value="">None / Standalone Account</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= e($e['full_name']) ?> (<?= e($e['staff_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password *</label>
                <input type="password" name="password" class="form-control" placeholder="Min. 8 characters" required>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Account</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUserModal() { document.getElementById('userModal').style.display = 'flex'; }
function closeUserModal() { document.getElementById('userModal').style.display = 'none'; }
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
