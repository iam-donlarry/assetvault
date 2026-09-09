<?php
// modules/staff/index.php - Staff Directory & Asset Custodians
declare(strict_types=1);

$active_page = 'staff';
$page_title = 'Staff Directory';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('staff.view');

$search = trim($_GET['q'] ?? '');
$deptFilter = !empty($_GET['department']) ? (int)$_GET['department'] : null;

$where = ["e.employment_status = 'active'"];
$params = [];

if ($search !== '') {
    $where[] = "(e.full_name LIKE :s OR e.staff_id LIKE :s OR e.email LIKE :s OR e.job_title LIKE :s)";
    $params[':s'] = "%$search%";
}

if ($deptFilter) {
    $where[] = "e.department_id = :dept";
    $params[':dept'] = $deptFilter;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT e.*, d.name AS dept_name, m.full_name AS manager_name,
           (SELECT COUNT(*) FROM assets a WHERE a.current_holder_id = e.id AND a.lifecycle_status = 'assigned') AS active_assets_count
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN employees m ON e.line_manager_id = m.id
    WHERE $whereSql
    ORDER BY e.full_name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, building, floor FROM locations WHERE is_active = 1 ORDER BY building ASC, floor ASC, name ASC")->fetchAll();
$managers = $pdo->query("SELECT id, staff_id, full_name, job_title FROM employees WHERE employment_status = 'active' ORDER BY full_name ASC")->fetchAll();

$drawerError = '';
$formData = [];

$editStaffId = trim($_GET['edit'] ?? '');
$editEmpTarget = null;
if (!empty($editStaffId)) {
    foreach ($employees as $eItem) {
        if ($eItem['staff_id'] === $editStaffId) {
            $editEmpTarget = $eItem;
            break;
        }
    }
    if (!$editEmpTarget) {
        $findStmt = $pdo->prepare("SELECT * FROM employees WHERE staff_id = ? LIMIT 1");
        $findStmt->execute([$editStaffId]);
        $editEmpTarget = $findStmt->fetch();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_employee') {
        requirePermission('staff.manage');
        
        if (!verifyCsrfToken()) {
            $drawerError = 'Security validation failed (CSRF token mismatch). Please refresh and try again.';
        } else {
            $staff_id = strtoupper(trim($_POST['staff_id'] ?? ''));
            $full_name = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '') ?: null;
            $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
            $job_title = trim($_POST['job_title'] ?? '');
            $line_manager_id = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;
            $primary_location_id = !empty($_POST['primary_location_id']) ? (int)$_POST['primary_location_id'] : null;
            $employment_status = in_array($_POST['employment_status'] ?? '', ['active', 'onboarding']) ? $_POST['employment_status'] : 'active';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $m365_assigned = !empty($_POST['m365_assigned']) ? 1 : 0;
            $m365_license_type = $m365_assigned ? trim($_POST['m365_license_type'] ?? 'Microsoft 365 Business Standard') : null;
            $m365_assigned_date = $m365_assigned ? (!empty($_POST['m365_assigned_date']) ? $_POST['m365_assigned_date'] : date('Y-m-d')) : null;

            $formData = $_POST;

            if (empty($staff_id) || empty($full_name) || empty($email) || empty($department_id) || empty($job_title)) {
                $drawerError = 'Staff ID, Full Name, Work Email, Department, and Job Title are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $drawerError = 'Please provide a valid work email address.';
            } else {
                // Check uniqueness of staff_id
                $chkId = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE staff_id = ?");
                $chkId->execute([$staff_id]);
                if ((int)$chkId->fetchColumn() > 0) {
                    $drawerError = "Staff ID '{$staff_id}' is already assigned to another employee.";
                } else {
                    // Check uniqueness of email
                    $chkEmail = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ?");
                    $chkEmail->execute([$email]);
                    if ((int)$chkEmail->fetchColumn() > 0) {
                        $drawerError = "Email address '{$email}' is already registered.";
                    } else {
                        try {
                            $insertStmt = $pdo->prepare("
                                INSERT INTO employees (
                                    staff_id, full_name, email, phone, department_id, job_title,
                                    line_manager_id, primary_location_id, employment_status,
                                    m365_assigned, m365_license_type, m365_assigned_date, start_date
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insertStmt->execute([
                                $staff_id,
                                $full_name,
                                $email,
                                $phone,
                                $department_id,
                                $job_title,
                                $line_manager_id,
                                $primary_location_id,
                                $employment_status,
                                $m365_assigned,
                                $m365_license_type,
                                $m365_assigned_date,
                                $start_date
                            ]);
                            $newId = (int)$pdo->lastInsertId();

                            logAudit('staff.create', 'employees', $newId, null, [
                                'staff_id' => $staff_id,
                                'full_name' => $full_name,
                                'department_id' => $department_id,
                                'job_title' => $job_title,
                                'm365_assigned' => $m365_assigned
                            ]);

                            flashMessage('success', "Employee '{$full_name}' ({$staff_id}) registered successfully.");
                            redirect("staff/view/" . urlencode($staff_id));
                        } catch (Exception $e) {
                            $drawerError = 'Database error: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    } elseif ($action === 'update_employee') {
        requirePermission('staff.manage');

        if (!verifyCsrfToken()) {
            flashMessage('error', 'Security validation failed (CSRF token mismatch).');
            redirect('staff');
        } else {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $staff_id = strtoupper(trim($_POST['staff_id'] ?? ''));
            $full_name = trim($_POST['full_name'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $phone = trim($_POST['phone'] ?? '') ?: null;
            $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : 0;
            $job_title = trim($_POST['job_title'] ?? '');
            $line_manager_id = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;
            $primary_location_id = !empty($_POST['primary_location_id']) ? (int)$_POST['primary_location_id'] : null;
            $employment_status = in_array($_POST['employment_status'] ?? '', ['active', 'onboarding', 'offboarding', 'exited', 'suspended']) ? $_POST['employment_status'] : 'active';
            $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $m365_assigned = !empty($_POST['m365_assigned']) ? 1 : 0;
            $m365_license_type = $m365_assigned ? trim($_POST['m365_license_type'] ?? 'Microsoft 365 Business Standard') : null;
            $m365_assigned_date = $m365_assigned ? (!empty($_POST['m365_assigned_date']) ? $_POST['m365_assigned_date'] : date('Y-m-d')) : null;

            $oldStmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $oldStmt->execute([$employee_id]);
            $oldEmp = $oldStmt->fetch();

            if (!$oldEmp) {
                flashMessage('error', 'Employee record not found.');
                redirect('staff');
            } elseif (empty($staff_id) || empty($full_name) || empty($email) || empty($department_id) || empty($job_title)) {
                flashMessage('error', 'Staff ID, Full Name, Work Email, Department, and Job Title are required.');
                redirect('staff?edit=' . urlencode($staff_id));
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flashMessage('error', 'Please provide a valid work email address.');
                redirect('staff?edit=' . urlencode($staff_id));
            } else {
                // Check unique staff_id
                $chkId = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE staff_id = ? AND id != ?");
                $chkId->execute([$staff_id, $employee_id]);
                if ((int)$chkId->fetchColumn() > 0) {
                    flashMessage('error', "Staff ID '{$staff_id}' is already assigned to another employee.");
                    redirect('staff?edit=' . urlencode($oldEmp['staff_id']));
                } else {
                    // Check unique email
                    $chkEmail = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email = ? AND id != ?");
                    $chkEmail->execute([$email, $employee_id]);
                    if ((int)$chkEmail->fetchColumn() > 0) {
                        flashMessage('error', "Email address '{$email}' is already registered to another employee.");
                        redirect('staff?edit=' . urlencode($oldEmp['staff_id']));
                    } else {
                        try {
                            $updateStmt = $pdo->prepare("
                                UPDATE employees SET
                                    staff_id = ?,
                                    full_name = ?,
                                    email = ?,
                                    phone = ?,
                                    department_id = ?,
                                    job_title = ?,
                                    line_manager_id = ?,
                                    primary_location_id = ?,
                                    employment_status = ?,
                                    m365_assigned = ?,
                                    m365_license_type = ?,
                                    m365_assigned_date = ?,
                                    start_date = ?
                                WHERE id = ?
                            ");
                            $updateStmt->execute([
                                $staff_id,
                                $full_name,
                                $email,
                                $phone,
                                $department_id,
                                $job_title,
                                $line_manager_id,
                                $primary_location_id,
                                $employment_status,
                                $m365_assigned,
                                $m365_license_type,
                                $m365_assigned_date,
                                $start_date,
                                $employee_id
                            ]);

                            logAudit('staff.update', 'employees', $employee_id, $oldEmp, [
                                'staff_id' => $staff_id,
                                'full_name' => $full_name,
                                'email' => $email,
                                'department_id' => $department_id,
                                'job_title' => $job_title,
                                'employment_status' => $employment_status
                            ]);

                            flashMessage('success', "Employee '{$full_name}' ({$staff_id}) updated successfully.");
                            redirect("staff/view/" . urlencode($staff_id));
                        } catch (Exception $e) {
                            flashMessage('error', 'Database error: ' . $e->getMessage());
                            redirect('staff?edit=' . urlencode($oldEmp['staff_id']));
                        }
                    }
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Staff Directory</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Employee custody directory, Microsoft 365 licensing, and assigned company equipment.
        </p>
    </div>
    <?php if (hasPermission('staff.manage')): ?>
    <button type="button" class="btn btn-primary" onclick="openStaffDrawer()">
        <i data-lucide="user-plus"></i>
        <span>Add Employee</span>
    </button>
    <?php endif; ?>
</div>

<div class="card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <form method="GET" action="<?= BASE_URL ?>/staff" style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <input type="text" name="q" class="form-control" placeholder="Search staff by name, ID, job title, email..." value="<?= e($search) ?>" style="max-width: 380px;">
        <select name="department" class="form-control" style="max-width: 220px;">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $deptFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary"><i data-lucide="search"></i> Search</button>
        <?php if ($search !== '' || $deptFilter): ?>
            <a href="<?= BASE_URL ?>/staff" class="btn btn-outline" title="Reset"><i data-lucide="rotate-ccw"></i></a>
        <?php endif; ?>
    </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container" style="border: none; border-radius: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Staff Name & ID</th>
                    <th>Job Title</th>
                    <th>Department</th>
                    <th>Line Manager</th>
                    <th>Microsoft 365</th>
                    <th>Assets Held</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div class="user-avatar-placeholder" style="width: 36px; height: 36px; font-weight: 700; font-size: 0.85rem;">
                                <?= strtoupper(substr($emp['full_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($emp['staff_id']) ?>" style="font-weight: 700; color: var(--text-primary); text-decoration: none;">
                                    <?= e($emp['full_name']) ?>
                                </a>
                                <div style="font-size: 0.775rem; color: var(--text-secondary);">
                                    ID: <span style="font-weight: 600; color: var(--primary-color);"><?= e($emp['staff_id']) ?></span> | <?= e($emp['email']) ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size: 0.85rem; font-weight: 500;">
                        <?= e($emp['job_title']) ?>
                    </td>
                    <td>
                        <span class="badge badge-secondary" style="font-size: 0.75rem;"><?= e($emp['dept_name']) ?></span>
                    </td>
                    <td style="font-size: 0.85rem; color: var(--text-secondary);">
                        <?= e($emp['manager_name'] ?? '—') ?>
                    </td>
                    <td>
                        <?php if ($emp['m365_assigned']): ?>
                            <span class="badge badge-success" title="M365 Account Provisioned">
                                <i data-lucide="check" style="width: 12px; height: 12px;"></i> Active
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary">None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $emp['active_assets_count'] > 0 ? 'badge-primary' : 'badge-secondary' ?>" style="font-size: 0.8rem; font-weight: 700;">
                            <?= $emp['active_assets_count'] ?> Assets
                        </span>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <div style="display: inline-flex; align-items: center; gap: 0.4rem;">
                            <?php if (hasPermission('staff.manage')): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick='openEditStaffDrawer(<?= json_encode($emp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Employee">
                                <i data-lucide="edit-3" style="width: 13px; height: 13px;"></i> Edit
                            </button>
                            <?php endif; ?>
                            <div class="action-dropdown">
                                <button class="action-dropdown-toggle">Actions ▾</button>
                                <div class="action-dropdown-menu">
                                    <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($emp['staff_id']) ?>" class="action-dropdown-item">
                                        <i data-lucide="eye" style="width: 14px; height: 14px;"></i> View Profile
                                    </a>
                                    <?php if (hasPermission('staff.manage')): ?>
                                    <button type="button" class="action-dropdown-item" onclick='openEditStaffDrawer(<?= json_encode($emp, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        <i data-lucide="edit-3" style="width: 14px; height: 14px; color: var(--primary-color);"></i> Edit Staff
                                    </button>
                                    <?php endif; ?>
                                    <?php if (hasPermission('assets.assign')): ?>
                                    <a href="<?= BASE_URL ?>/assignments/checkout?staff_id=<?= $emp['id'] ?>" class="action-dropdown-item">
                                        <i data-lucide="plus-circle" style="width: 14px; height: 14px;"></i> Issue Equipment
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasPermission('offboarding.manage')): ?>
                                    <a href="<?= BASE_URL ?>/offboarding?initiate_staff_id=<?= $emp['id'] ?>" class="action-dropdown-item danger">
                                        <i data-lucide="user-minus" style="width: 14px; height: 14px;"></i> Exit Clearance
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (hasPermission('staff.manage')): ?>
<!-- Off-Canvas Drawer for Adding Employee (50vw width, half screen) -->
<div class="offcanvas-overlay <?= !empty($drawerError) ? 'active' : '' ?>" id="staffDrawer" onclick="handleDrawerBackdrop(event)">
    <div class="offcanvas-drawer" onclick="event.stopPropagation()">
        <div class="offcanvas-header">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="user-plus" style="color: var(--primary); width: 22px; height: 22px;"></i>
                    <span>Add New Employee</span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.825rem; color: var(--text-secondary);">
                    Register team member, assign department custody & setup Microsoft 365 licensing.
                </p>
            </div>
            <button type="button" class="btn-icon-toggle" onclick="closeStaffDrawer()" title="Close Drawer" style="border-radius: 50%; width: 32px; height: 32px; padding: 0;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/staff" id="staffForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0;">
            <input type="hidden" name="action" value="create_employee">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="offcanvas-body">
                <?php if (!empty($drawerError)): ?>
                <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1rem; border-radius: var(--radius-sm); margin-bottom: 1.25rem; font-size: 0.875rem; display: flex; align-items: center; gap: 0.6rem;">
                    <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                    <div><?= e($drawerError) ?></div>
                </div>
                <?php endif; ?>

                <!-- Section 1: Personal & Identification -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="id-card" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Personal & Identification</span>
                    </h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="staff_id">Staff ID / Code <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="staff_id" id="staff_id" class="form-control" placeholder="e.g. 240901 or EMP-010" value="<?= e($formData['staff_id'] ?? '') ?>" required>
                            <small style="color: var(--text-secondary); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Unique corporate employee identifier.</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="full_name">Full Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="full_name" id="full_name" class="form-control" placeholder="e.g. Jane Doe" value="<?= e($formData['full_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="email">Work Email <span style="color: var(--danger);">*</span></label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="j.doe@bayebusinesssolutions.com" value="<?= e($formData['email'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="phone">Phone Number</label>
                            <input type="tel" name="phone" id="phone" class="form-control" placeholder="+234 803 000 0000" value="<?= e($formData['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Department & Employment Details -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="briefcase" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Department & Placement</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="department_id">Department <span style="color: var(--danger);">*</span></label>
                            <select name="department_id" id="department_id" class="form-control" required>
                                <option value="">Select Department...</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ((int)($formData['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="job_title">Job Title / Designation <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="job_title" id="job_title" class="form-control" placeholder="e.g. Senior Frontend Engineer" value="<?= e($formData['job_title'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="line_manager_id">Line Manager / Supervisor</label>
                            <select name="line_manager_id" id="line_manager_id" class="form-control">
                                <option value="">No Direct Manager (Reports to Head)</option>
                                <?php foreach ($managers as $mgr): ?>
                                <option value="<?= $mgr['id'] ?>" <?= ((int)($formData['line_manager_id'] ?? 0) === (int)$mgr['id']) ? 'selected' : '' ?>>
                                    <?= e($mgr['full_name']) ?> (<?= e($mgr['job_title']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="primary_location_id">Primary Work Location</label>
                            <select name="primary_location_id" id="primary_location_id" class="form-control">
                                <option value="">Select Primary Room / Station...</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= ((int)($formData['primary_location_id'] ?? 0) === (int)$loc['id']) ? 'selected' : '' ?>>
                                    <?= e($loc['name']) ?> (<?= e($loc['building']) ?> - <?= e($loc['floor']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="employment_status">Employment Status</label>
                            <select name="employment_status" id="employment_status" class="form-control">
                                <option value="active" <?= (($formData['employment_status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active Employee</option>
                                <option value="onboarding" <?= (($formData['employment_status'] ?? '') === 'onboarding') ? 'selected' : '' ?>>Incoming / In Onboarding</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="start_date">Employment Start Date</label>
                            <input type="date" name="start_date" id="start_date" class="form-control" value="<?= e($formData['start_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Microsoft 365 & IT Workspace Provisioning -->
                <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1.15rem; margin-bottom: 0.5rem;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i data-lucide="cloud" style="width: 20px; height: 20px;"></i>
                            </div>
                            <div>
                                <label for="m365_assigned" style="font-weight: 700; margin-bottom: 0.15rem; cursor: pointer; color: var(--text-primary); font-size: 0.925rem;">
                                    Microsoft 365 Account Provisioned
                                </label>
                                <p style="margin: 0; font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4;">
                                    Enable if user has an active corporate Microsoft 365 tenant license.
                                </p>
                            </div>
                        </div>
                        <input type="checkbox" name="m365_assigned" id="m365_assigned" value="1" <?= (!isset($formData['m365_assigned']) || !empty($formData['m365_assigned'])) ? 'checked' : '' ?> onchange="toggleM365Fields(this.checked)" style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; margin-top: 4px;">
                    </div>

                    <div id="m365Fields" style="margin-top: 1.15rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; display: <?= (!isset($formData['m365_assigned']) || !empty($formData['m365_assigned'])) ? 'grid' : 'none' ?>; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="m365_license_type">M365 License Plan</label>
                            <select name="m365_license_type" id="m365_license_type" class="form-control">
                                <option value="Microsoft 365 Business Standard" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft 365 Business Standard') ? 'selected' : '' ?>>Microsoft 365 Business Standard</option>
                                <option value="Microsoft 365 Business Basic" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft 365 Business Basic') ? 'selected' : '' ?>>Microsoft 365 Business Basic</option>
                                <option value="Microsoft 365 Business Premium" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft 365 Business Premium') ? 'selected' : '' ?>>Microsoft 365 Business Premium</option>
                                <option value="Microsoft 365 Enterprise E3" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft 365 Enterprise E3') ? 'selected' : '' ?>>Microsoft 365 Enterprise E3</option>
                                <option value="Microsoft 365 Enterprise E5" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft 365 Enterprise E5') ? 'selected' : '' ?>>Microsoft 365 Enterprise E5</option>
                                <option value="Microsoft Exchange Online (Plan 1)" <?= (($formData['m365_license_type'] ?? '') === 'Microsoft Exchange Online (Plan 1)') ? 'selected' : '' ?>>Microsoft Exchange Online (Plan 1)</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="m365_assigned_date">Provisioning Date</label>
                            <input type="date" name="m365_assigned_date" id="m365_assigned_date" class="form-control" value="<?= e($formData['m365_assigned_date'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer" style="display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeStaffDrawer()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="check"></i> <span>Save Employee</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Off-Canvas Drawer for Editing Employee (50vw width, half screen) -->
<div class="offcanvas-overlay" id="editStaffDrawer" onclick="handleEditDrawerBackdrop(event)">
    <div class="offcanvas-drawer" onclick="event.stopPropagation()">
        <div class="offcanvas-header">
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem;">
                    <i data-lucide="user-cog" style="color: var(--primary); width: 22px; height: 22px;"></i>
                    <span id="editDrawerTitle">Edit Employee</span>
                </h3>
                <p style="margin: 0.25rem 0 0; font-size: 0.825rem; color: var(--text-secondary);">
                    Modify employee credentials, department custody, active status, and Microsoft 365 licensing.
                </p>
            </div>
            <button type="button" class="btn-icon-toggle" onclick="closeEditStaffDrawer()" title="Close Drawer" style="border-radius: 50%; width: 32px; height: 32px; padding: 0;">
                <i data-lucide="x" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/staff" id="editStaffForm" style="display: flex; flex-direction: column; flex: 1; min-height: 0; margin: 0;">
            <input type="hidden" name="action" value="update_employee">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="employee_id" id="edit_employee_id">

            <div class="offcanvas-body">
                <!-- Section 1: Personal & Identification -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="id-card" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Personal & Identification</span>
                    </h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_staff_id">Staff ID / Code <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="staff_id" id="edit_staff_id" class="form-control" required>
                            <small style="color: var(--text-secondary); font-size: 0.75rem; display: block; margin-top: 0.25rem;">Unique corporate employee identifier.</small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_full_name">Full Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_email">Work Email <span style="color: var(--danger);">*</span></label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_phone">Phone Number</label>
                            <input type="tel" name="phone" id="edit_phone" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Department & Employment Details -->
                <div style="margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary); font-weight: 700; margin-bottom: 0.85rem; display: flex; align-items: center; gap: 0.45rem;">
                        <i data-lucide="briefcase" style="width: 16px; height: 16px; color: var(--primary);"></i>
                        <span>Department & Placement</span>
                    </h4>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_department_id">Department <span style="color: var(--danger);">*</span></label>
                            <select name="department_id" id="edit_department_id" class="form-control" required>
                                <option value="">Select Department...</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_job_title">Job Title / Designation <span style="color: var(--danger);">*</span></label>
                            <input type="text" name="job_title" id="edit_job_title" class="form-control" required>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_line_manager_id">Line Manager / Supervisor</label>
                            <select name="line_manager_id" id="edit_line_manager_id" class="form-control">
                                <option value="">No Direct Manager (Reports to Head)</option>
                                <?php foreach ($managers as $mgr): ?>
                                <option value="<?= $mgr['id'] ?>"><?= e($mgr['full_name']) ?> (<?= e($mgr['job_title']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_primary_location_id">Primary Work Location</label>
                            <select name="primary_location_id" id="edit_primary_location_id" class="form-control">
                                <option value="">Select Primary Room / Station...</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?> (<?= e($loc['building']) ?> - <?= e($loc['floor']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label" for="edit_employment_status">Employment Status</label>
                            <select name="employment_status" id="edit_employment_status" class="form-control">
                                <option value="active">Active Employee</option>
                                <option value="onboarding">Incoming / In Onboarding</option>
                                <option value="offboarding">Offboarding in Progress</option>
                                <option value="exited">Exited / Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="edit_start_date">Employment Start Date</label>
                            <input type="date" name="start_date" id="edit_start_date" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Microsoft 365 & IT Workspace Provisioning -->
                <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 1.15rem; margin-bottom: 0.5rem;">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;">
                        <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                            <div style="width: 36px; height: 36px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i data-lucide="cloud" style="width: 20px; height: 20px;"></i>
                            </div>
                            <div>
                                <label for="edit_m365_assigned" style="font-weight: 700; margin-bottom: 0.15rem; cursor: pointer; color: var(--text-primary); font-size: 0.925rem;">
                                    Microsoft 365 Account Provisioned
                                </label>
                                <p style="margin: 0; font-size: 0.8rem; color: var(--text-secondary); line-height: 1.4;">
                                    Enable if user has an active corporate Microsoft 365 tenant license.
                                </p>
                            </div>
                        </div>
                        <input type="checkbox" name="m365_assigned" id="edit_m365_assigned" value="1" onchange="toggleEditM365Fields(this.checked)" style="width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; margin-top: 4px;">
                    </div>

                    <div id="editM365Fields" style="margin-top: 1.15rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; display: none; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="edit_m365_license_type">M365 License Plan</label>
                            <select name="m365_license_type" id="edit_m365_license_type" class="form-control">
                                <option value="Microsoft 365 Business Standard">Microsoft 365 Business Standard</option>
                                <option value="Microsoft 365 Business Basic">Microsoft 365 Business Basic</option>
                                <option value="Microsoft 365 Business Premium">Microsoft 365 Business Premium</option>
                                <option value="Microsoft 365 Enterprise E3">Microsoft 365 Enterprise E3</option>
                                <option value="Microsoft 365 Enterprise E5">Microsoft 365 Enterprise E5</option>
                                <option value="Microsoft Exchange Online (Plan 1)">Microsoft Exchange Online (Plan 1)</option>
                            </select>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="edit_m365_assigned_date">Provisioning Date</label>
                            <input type="date" name="m365_assigned_date" id="edit_m365_assigned_date" class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <div class="offcanvas-footer" style="display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem;">
                <button type="button" class="btn btn-outline" onclick="closeEditStaffDrawer()">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="check"></i> <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openStaffDrawer() {
    closeEditStaffDrawer();
    const drawer = document.getElementById('staffDrawer');
    if (drawer) {
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            const firstInput = document.getElementById('staff_id');
            if (firstInput) firstInput.focus();
            if (window.lucide) lucide.createIcons();
        }, 150);
    }
}

function closeStaffDrawer() {
    const drawer = document.getElementById('staffDrawer');
    if (drawer) {
        drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function handleDrawerBackdrop(e) {
    if (e.target.id === 'staffDrawer') {
        closeStaffDrawer();
    }
}

function toggleM365Fields(isChecked) {
    const box = document.getElementById('m365Fields');
    if (box) {
        box.style.display = isChecked ? 'grid' : 'none';
    }
}

function openEditStaffDrawer(data) {
    if (!data) return;
    closeStaffDrawer();

    document.getElementById('edit_employee_id').value = data.id || '';
    document.getElementById('edit_staff_id').value = data.staff_id || '';
    document.getElementById('edit_full_name').value = data.full_name || '';
    document.getElementById('edit_email').value = data.email || '';
    document.getElementById('edit_phone').value = data.phone || '';
    document.getElementById('edit_department_id').value = data.department_id || '';
    document.getElementById('edit_job_title').value = data.job_title || '';
    document.getElementById('edit_line_manager_id').value = data.line_manager_id || '';
    document.getElementById('edit_primary_location_id').value = data.primary_location_id || '';
    document.getElementById('edit_employment_status').value = data.employment_status || 'active';
    document.getElementById('edit_start_date').value = data.start_date || '';

    const titleEl = document.getElementById('editDrawerTitle');
    if (titleEl) {
        titleEl.textContent = 'Edit: ' + (data.full_name || 'Employee');
    }

    const isM365 = parseInt(data.m365_assigned || 0) === 1;
    const m365Box = document.getElementById('edit_m365_assigned');
    if (m365Box) m365Box.checked = isM365;

    const planEl = document.getElementById('edit_m365_license_type');
    if (planEl && data.m365_license_type) planEl.value = data.m365_license_type;

    const dateEl = document.getElementById('edit_m365_assigned_date');
    if (dateEl) dateEl.value = data.m365_assigned_date || '';

    toggleEditM365Fields(isM365);

    const drawer = document.getElementById('editStaffDrawer');
    if (drawer) {
        drawer.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(function() {
            if (window.lucide) lucide.createIcons();
            const firstInput = document.getElementById('edit_full_name');
            if (firstInput) firstInput.focus();
        }, 150);
    }
}

function closeEditStaffDrawer() {
    const drawer = document.getElementById('editStaffDrawer');
    if (drawer) {
        drawer.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function handleEditDrawerBackdrop(e) {
    if (e.target.id === 'editStaffDrawer') {
        closeEditStaffDrawer();
    }
}

function toggleEditM365Fields(isChecked) {
    const box = document.getElementById('editM365Fields');
    if (box) {
        box.style.display = isChecked ? 'grid' : 'none';
    }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeStaffDrawer();
        closeEditStaffDrawer();
    }
});

<?php if (!empty($drawerError)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openStaffDrawer();
});
<?php endif; ?>

<?php if (!empty($editEmpTarget)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openEditStaffDrawer(<?= json_encode($editEmpTarget, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
