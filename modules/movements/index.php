<?php
// modules/movements/index.php - Location & Custody Movement Ledger
declare(strict_types=1);

$active_page = 'movements';
$page_title = 'Movements Ledger';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$search = trim($_GET['q'] ?? '');
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(a.asset_tag LIKE :s OR a.item_name LIKE :s OR m.reason LIKE :s OR tl.name LIKE :s OR te.full_name LIKE :s)";
    $params[':s'] = "%$search%";
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT m.*, 
           a.asset_tag, a.item_name, c.name AS category_name,
           fl.name AS from_location, tl.name AS to_location,
           fe.full_name AS from_employee, te.full_name AS to_employee,
           u.full_name AS moved_by_name
    FROM asset_movements m
    JOIN assets a ON m.asset_id = a.id
    JOIN asset_categories c ON a.category_id = c.id
    LEFT JOIN locations fl ON m.from_location_id = fl.id
    JOIN locations tl ON m.to_location_id = tl.id
    LEFT JOIN employees fe ON m.from_employee_id = fe.id
    LEFT JOIN employees te ON m.to_employee_id = te.id
    LEFT JOIN users u ON m.moved_by_user_id = u.id
    $whereSql
    ORDER BY m.movement_date DESC, m.id DESC
");
$stmt->execute($params);
$movements = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">Movement & Transfer Ledger</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Immutable audit record of all inter-office transits, custody handovers, and store returns.
        </p>
    </div>
</div>

<div class="card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <form method="GET" action="<?= BASE_URL ?>/movements" style="display: flex; gap: 0.75rem;">
        <input type="text" name="q" class="form-control" placeholder="Search by asset tag, room, person, or reason..." value="<?= e($search) ?>" style="max-width: 400px;">
        <button type="submit" class="btn btn-primary"><i data-lucide="search"></i> Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/movements" class="btn btn-outline" title="Clear"><i data-lucide="rotate-ccw"></i></a>
        <?php endif; ?>
    </form>
</div>

<div class="card" style="padding: 0; overflow: hidden;">
    <?php if (empty($movements)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-secondary);">
            <i data-lucide="truck" style="width: 48px; height: 48px; opacity: 0.4; margin-bottom: 0.75rem;"></i>
            <h4>No movements recorded yet</h4>
            <p style="font-size: 0.875rem;">Physical transit records will automatically populate here upon asset movements.</p>
        </div>
    <?php else: ?>
        <div class="table-container" style="border: none; border-radius: 0;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Asset Tag & Name</th>
                        <th>Source (From)</th>
                        <th>Destination (To)</th>
                        <th>Reason</th>
                        <th>Authorized By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m): ?>
                    <tr>
                        <td style="font-size: 0.825rem; color: var(--text-secondary); white-space: nowrap;">
                            <?= date('M d, Y H:i', strtotime($m['movement_date'])) ?>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/view/<?= urlencode($m['asset_tag']) ?>" style="font-weight: 700; color: var(--primary-color);">
                                <?= e($m['asset_tag']) ?>
                            </a>
                            <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= e($m['item_name']) ?></div>
                        </td>
                        <td>
                            <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                <?= e($m['from_location'] ?? ($m['from_employee'] ? 'Staff: ' . $m['from_employee'] : 'External / New')) ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-weight: 600; font-size: 0.85rem; color: var(--text-primary);">
                                <?= e($m['to_location']) ?>
                                <?php if ($m['to_employee']): ?>
                                    <div style="font-size: 0.75rem; color: var(--primary-color);">Custodian: <?= e($m['to_employee']) ?></div>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-size: 0.725rem;"><?= e($m['reason']) ?></span>
                        </td>
                        <td style="font-size: 0.825rem; color: var(--text-secondary);">
                            <?= e($m['moved_by_name'] ?? 'System') ?>
                        </td>
                        <td style="font-size: 0.825rem; color: var(--text-secondary); max-width: 250px;">
                            <?= e($m['notes'] ?? '—') ?>
                        </td>
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
