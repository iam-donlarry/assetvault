<?php
// modules/reports/index.php - Reports & Analytics Hub with CSV Streaming
declare(strict_types=1);

$active_page = 'reports';
$page_title = 'Reports & Analytics';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('reports.view');

// Handle CSV Exports
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];

    if ($exportType === 'assets') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=asset_register_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Asset Tag', 'Legacy ID', 'Item Name', 'Category', 'Brand', 'Model', 'Serial Number', 'Custodian', 'Staff ID', 'Location', 'Condition', 'Status', 'Currency', 'Purchase Cost', 'Warranty Expiry']);

        $stmt = $pdo->query("
            SELECT a.asset_tag, a.legacy_asset_id, a.item_name, c.name AS cat_name, a.brand, a.model, a.serial_number,
                   e.full_name AS custodian, e.staff_id, l.name AS location_name, a.condition_status, a.lifecycle_status,
                   a.purchase_currency, a.purchase_cost, a.warranty_expiry_date
            FROM assets a
            JOIN asset_categories c ON a.category_id = c.id
            JOIN locations l ON a.current_location_id = l.id
            LEFT JOIN employees e ON a.current_holder_id = e.id
            ORDER BY a.id ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($exportType === 'staff') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=staff_asset_allocation_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Staff ID', 'Full Name', 'Department', 'Job Title', 'Email', 'M365 Assigned', 'Active Equipment Count']);

        $stmt = $pdo->query("
            SELECT e.staff_id, e.full_name, d.name AS dept_name, e.job_title, e.email, 
                   IF(e.m365_assigned=1, 'Yes', 'No'),
                   (SELECT COUNT(*) FROM assets a WHERE a.current_holder_id = e.id AND a.lifecycle_status = 'assigned')
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE e.employment_status = 'active'
            ORDER BY e.full_name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    if ($exportType === 'inventory') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=bulk_inventory_stock_' . date('Y-m-d') . '.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Product Code', 'Brand', 'Model Name', 'Part Number', 'Category', 'Location', 'Available Qty', 'Assigned Qty', 'Damaged Qty', 'Repair Qty', 'Total Owned', 'Unit of Measure', 'Average Unit Cost', 'Total Valuation']);

        $stmt = $pdo->query("
            SELECT p.product_code, p.brand, p.model_name, p.model_number, c.name AS cat_name,
                   l.name AS location_name, s.quantity_available, s.quantity_assigned, s.quantity_damaged, s.quantity_under_repair,
                   (s.quantity_available + s.quantity_assigned + s.quantity_damaged + s.quantity_under_repair) AS total_owned,
                   p.unit_of_measure, p.average_unit_cost,
                   ((s.quantity_available + s.quantity_assigned + s.quantity_damaged + s.quantity_under_repair) * p.average_unit_cost) AS valuation
            FROM inventory_stock s
            JOIN product_models p ON s.product_id = p.id
            JOIN asset_categories c ON p.category_id = c.id
            JOIN locations l ON s.location_id = l.id
            ORDER BY c.name ASC, p.brand ASC, p.model_name ASC, l.name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

// Summary Metrics for View
$serTotal = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE lifecycle_status != 'disposed'")->fetchColumn();
$bulkTotal = (int)$pdo->query("SELECT COALESCE(SUM(quantity_available + quantity_assigned + quantity_damaged + quantity_under_repair), 0) FROM inventory_stock")->fetchColumn();
$totalAssets = $serTotal + $bulkTotal;

// Category breakdown (Combining serialized physical assets + bulk inventory units)
$catStats = $pdo->query("
    SELECT c.name,
           (
               COALESCE((SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id AND a.lifecycle_status != 'disposed'), 0) +
               COALESCE((SELECT SUM(s.quantity_available + s.quantity_assigned + s.quantity_damaged + s.quantity_under_repair) 
                         FROM inventory_stock s 
                         JOIN product_models p ON s.product_id = p.id 
                         WHERE p.category_id = c.id), 0)
           ) AS count
    FROM asset_categories c
    ORDER BY count DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 0.6rem;">
            <i data-lucide="bar-chart-3" style="color: var(--primary); width: 28px; height: 28px;"></i>
            <span>Reports & Analytics Hub</span>
        </h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Export serialized equipment registers, bulk stock valuations, and staff allocation manifests.
        </p>
    </div>
</div>

<!-- Export Action Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.75rem;">
                <div class="stat-icon bg-primary-light">
                    <i data-lucide="file-spreadsheet" style="width: 22px; height: 22px;"></i>
                </div>
                <h3 style="font-size: 1.1rem; margin-bottom: 0;">Serialized Asset Register</h3>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5;">
                Full physical asset dump with tags, serial numbers, product models, custodians, and room locations.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/reports?export=assets" class="btn btn-primary" style="margin-top: 1rem;">
            <i data-lucide="download"></i> Download Asset CSV
        </a>
    </div>

    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.75rem;">
                <div class="stat-icon" style="background: #ecfdf5; color: #059669;">
                    <i data-lucide="boxes" style="width: 22px; height: 22px;"></i>
                </div>
                <h3 style="font-size: 1.1rem; margin-bottom: 0;">Bulk Stock & Valuation</h3>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5;">
                Consumable inventory balances per room, available/assigned buckets, and Weighted Average Cost (WAC) valuation.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/reports?export=inventory" class="btn btn-primary" style="margin-top: 1rem;">
            <i data-lucide="download"></i> Download Stock CSV
        </a>
    </div>

    <div class="card" style="margin-bottom: 0; display: flex; flex-direction: column; justify-content: space-between;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.75rem;">
                <div class="stat-icon" style="background: #e0f2fe; color: #0284c7;">
                    <i data-lucide="users" style="width: 22px; height: 22px;"></i>
                </div>
                <h3 style="font-size: 1.1rem; margin-bottom: 0;">Staff Equipment Allocation</h3>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5;">
                Summary of active staff members, total assigned equipment count, department, and Microsoft 365 licensing.
            </p>
        </div>
        <a href="<?= BASE_URL ?>/reports?export=staff" class="btn btn-primary" style="margin-top: 1rem;">
            <i data-lucide="download"></i> Download Staff CSV
        </a>
    </div>
</div>

<!-- Category Distribution Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); background: #ffffff;">
        <h3 style="font-size: 1.1rem; margin-bottom: 0;">Inventory Distribution by Category</h3>
    </div>

    <div class="table-container" style="border: none; border-radius: 0;">
        <table class="table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Count</th>
                    <th>Percentage of Inventory</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catStats as $cs): 
                    $pct = $totalAssets > 0 ? round(($cs['count'] / $totalAssets) * 100, 1) : 0;
                ?>
                <tr>
                    <td style="font-weight: 600;"><?= e($cs['name']) ?></td>
                    <td style="font-weight: 700; color: var(--primary-color);"><?= $cs['count'] ?></td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="flex: 1; height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; max-width: 200px;">
                                <div style="height: 100%; width: <?= $pct ?>%; background: #12b1b0;"></div>
                            </div>
                            <span style="font-size: 0.825rem; font-weight: 600; color: var(--text-secondary);"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
