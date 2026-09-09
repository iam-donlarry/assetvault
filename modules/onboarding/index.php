<?php
// modules/onboarding/index.php - New Hire Asset Provisioning
declare(strict_types=1);

$active_page = 'onboarding';
$page_title = 'Staff Onboarding Provisioning';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('onboarding.view');

$requests = $pdo->query("
    SELECT o.*, e.full_name, e.staff_id, e.job_title, d.name AS dept_name,
           (SELECT COUNT(*) FROM onboarding_items oi WHERE oi.onboarding_request_id = o.id) AS total_items,
           (SELECT COUNT(*) FROM onboarding_items oi WHERE oi.onboarding_request_id = o.id AND oi.is_fulfilled = 1) AS fulfilled_items
    FROM onboarding_requests o
    JOIN employees e ON o.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    ORDER BY o.target_start_date DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Onboarding Provisioning</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Role-based asset bundle allocation and equipment readiness pipeline for new employees.
        </p>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($requests)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
            <i data-lucide="user-check" style="width: 48px; height: 48px; color: #10b981; margin-bottom: 0.75rem;"></i>
            <h4>All onboarding hardware requests fulfilled</h4>
            <p style="font-size: 0.875rem;">No incoming employees currently require hardware allocation.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Request Code</th>
                        <th>New Hire</th>
                        <th>Department</th>
                        <th>Target Start Date</th>
                        <th>Bundle Template</th>
                        <th>Checklist Readiness</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--primary-color);"><?= e($r['request_code']) ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/staff/view/<?= urlencode($r['staff_id']) ?>" style="font-weight: 600; color: var(--text-primary);">
                                <?= e($r['full_name']) ?>
                            </a>
                            <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= e($r['job_title']) ?></div>
                        </td>
                        <td><span class="badge badge-secondary"><?= e($r['dept_name']) ?></span></td>
                        <td style="font-size: 0.85rem;"><?= formatDate($r['target_start_date']) ?></td>
                        <td><?= e($r['bundle_name']) ?></td>
                        <td>
                            <span class="badge badge-info"><?= $r['fulfilled_items'] ?> / <?= $r['total_items'] ?> Fulfilled</span>
                        </td>
                        <td><?= getBadgeForStatus($r['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
