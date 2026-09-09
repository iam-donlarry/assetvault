<?php
// modules/audit/index.php - System Audit Trail & Security Event Viewer
declare(strict_types=1);

$active_page = 'audit';
$page_title = 'System Audit Trail';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('audit.view');

$logs = $pdo->query("
    SELECT * 
    FROM audit_logs 
    ORDER BY created_at DESC, id DESC 
    LIMIT 100
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">System Audit Trail</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Immutable historical record of all data mutations, security logins, and asset custody state changes.
        </p>
    </div>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-container" style="border: none; border-radius: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Entity Type</th>
                    <th>Entity ID</th>
                    <th>IP Address</th>
                    <th>Changes Logged</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                        No system audit records logged yet.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td style="font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap;">
                            <?= date('M d, Y H:i:s', strtotime($l['created_at'])) ?>
                        </td>
                        <td style="font-weight: 600; font-size: 0.85rem;">
                            <?= e($l['user_name']) ?>
                        </td>
                        <td>
                            <code style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: var(--primary-color); font-size: 0.8rem;">
                                <?= e($l['action']) ?>
                            </code>
                        </td>
                        <td style="font-size: 0.85rem;"><?= e($l['entity_type']) ?></td>
                        <td style="font-weight: 600; font-size: 0.85rem;">#<?= $l['entity_id'] ?></td>
                        <td style="font-size: 0.8rem; color: var(--text-secondary); font-family: monospace;">
                            <?= e($l['ip_address']) ?>
                        </td>
                        <td style="font-size: 0.775rem; color: var(--text-secondary); max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            <?php if ($l['new_values']): ?>
                                <span title="<?= e($l['new_values']) ?>"><?= e($l['new_values']) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
