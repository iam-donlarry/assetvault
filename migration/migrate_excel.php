<?php
declare(strict_types=1);

// migration/migrate_excel.php - Normalized Excel Data Importer & Reconciler
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

echo "================================================================\n";
echo " AssetVault — Enhanced Staff Asset Register Migration Pipeline\n";
echo "================================================================\n\n";

$excelFile = dirname(__DIR__) . '/Enhanced Staff Asset Register.xlsx';
if (!file_exists($excelFile)) {
    die("ERROR: Workbook not found at $excelFile\n");
}

echo "1. Reading workbook data via OpenPyXL bridge...\n";

$pythonScript = __DIR__ . '/extract_excel.py';
$extractPy = <<<'PY'
import openpyxl, json, sys

excel_path = sys.argv[1]
wb = openpyxl.load_workbook(excel_path, data_only=True)
ws = wb['Staff Asset Register']
headers = [str(c).strip() if c is not None else '' for c in next(ws.iter_rows(values_only=True))]

rows = []
for r in list(ws.iter_rows(values_only=True))[1:]:
    row_dict = {}
    for h, val in zip(headers, r):
        if h:
            row_dict[h] = val
    rows.append(row_dict)

print(json.dumps(rows, default=str))
PY;

file_put_contents($pythonScript, $extractPy);

$cmd = "python " . escapeshellarg($pythonScript) . " " . escapeshellarg($excelFile);
$jsonOutput = shell_exec($cmd);
unlink($pythonScript);

if (!$jsonOutput) {
    die("ERROR: Failed to extract data from Excel file.\n");
}

$rows = json_decode($jsonOutput, true);
if (!is_array($rows) || empty($rows)) {
    die("ERROR: Invalid JSON extracted from Excel.\n");
}

echo "   Extracted " . count($rows) . " rows successfully.\n\n";

// Begin Database Transaction
echo "2. Beginning atomic database import transaction...\n";
$pdo->beginTransaction();

try {
    // 2.1 Fetch Master Mapping Dictionaries
    $deptMap = $pdo->query("SELECT id, name, code FROM departments")->fetchAll();
    $deptByName = [];
    foreach ($deptMap as $d) {
        $deptByName[strtolower(trim($d['name']))] = (int)$d['id'];
    }

    $locMap = $pdo->query("SELECT id, name, code FROM locations")->fetchAll();
    $locByName = [];
    foreach ($locMap as $l) {
        $locByName[strtolower(trim($l['name']))] = (int)$l['id'];
    }

    $catMap = $pdo->query("SELECT id, name, code, is_serialized FROM asset_categories")->fetchAll();
    $catByName = [];
    foreach ($catMap as $c) {
        $catByName[strtolower(trim($c['name']))] = (int)$c['id'];
    }

    // Default Fallbacks
    $defaultDeptId = $deptByName['admin'] ?? 1;
    $defaultLocId = $locByName['open office'] ?? 1;

    // 2.2 Deduplicate & Normalize Staff Profiles
    echo "3. Normalizing Employee Profiles...\n";
    $staffRecords = [];
    $m365Map = [];

    foreach ($rows as $r) {
        $rawStaffId = (string)($r['Staff ID'] ?? '');
        $name = trim((string)($r['Staff Name'] ?? ''));
        $email = strtolower(trim((string)($r['Email Address'] ?? '')));
        $jobTitle = trim((string)($r['Job Title'] ?? 'Staff'));
        $deptName = trim((string)($r['Department'] ?? 'Admin'));
        $managerName = trim((string)($r['Line Manager'] ?? ''));
        $m365 = trim((string)($r['Microsoft 365 Assigned'] ?? ''));

        // Handle Basirat Shekoni ID Collision: Deduplicate 230202 to 230201
        if ($email === 'bshekoni@bayebusinesssolutions.com' || strtolower($name) === 'basirat shekoni') {
            $rawStaffId = '230201';
        }

        if (empty($rawStaffId) || empty($name)) continue;

        if (!isset($staffRecords[$rawStaffId])) {
            $staffRecords[$rawStaffId] = [
                'staff_id' => $rawStaffId,
                'name' => $name,
                'email' => $email,
                'title' => $jobTitle,
                'department_name' => $deptName,
                'manager_name' => $managerName,
                'm365_assigned' => 0
            ];
        }

        if (strtolower($m365) === 'yes') {
            $staffRecords[$rawStaffId]['m365_assigned'] = 1;
        }
    }

    // Insert Employees (First pass without line managers)
    $empStmt = $pdo->prepare("
        INSERT INTO employees (staff_id, full_name, email, department_id, job_title, m365_assigned, employment_status)
        VALUES (?, ?, ?, ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE 
            full_name = VALUES(full_name),
            department_id = VALUES(department_id),
            job_title = VALUES(job_title),
            m365_assigned = VALUES(m365_assigned)
    ");

    $staffIdToDbId = [];
    $staffNameToDbId = [];

    foreach ($staffRecords as $sid => $s) {
        $dId = $deptByName[strtolower($s['department_name'])] ?? $defaultDeptId;
        $empStmt->execute([
            $s['staff_id'],
            $s['name'],
            $s['email'],
            $dId,
            $s['title'],
            $s['m365_assigned']
        ]);

        $dbEmpId = (int)$pdo->lastInsertId();
        if ($dbEmpId === 0) {
            $q = $pdo->prepare("SELECT id FROM employees WHERE staff_id = ?");
            $q->execute([$s['staff_id']]);
            $dbEmpId = (int)$q->fetchColumn();
        }

        $staffIdToDbId[$s['staff_id']] = $dbEmpId;
        $staffNameToDbId[strtolower($s['name'])] = $dbEmpId;
        echo "   -> Employee created: {$s['name']} (Staff ID: {$s['staff_id']})\n";
    }

    // Second pass: Update Line Managers
    $mgrUpdStmt = $pdo->prepare("UPDATE employees SET line_manager_id = ? WHERE id = ?");
    foreach ($staffRecords as $sid => $s) {
        $mgrNameKey = strtolower($s['manager_name']);
        if (!empty($mgrNameKey) && isset($staffNameToDbId[$mgrNameKey])) {
            $mgrDbId = $staffNameToDbId[$mgrNameKey];
            $empDbId = $staffIdToDbId[$s['staff_id']];
            $mgrUpdStmt->execute([$mgrDbId, $empDbId]);
        }
    }

    // 2.3 Migrate Assets & Create Assignments
    echo "\n4. Migrating Assets & Establishing Chain of Custody...\n";

    $assetInsertStmt = $pdo->prepare("
        INSERT INTO assets (
            asset_tag, legacy_asset_id, category_id, item_name, brand, model, serial_number,
            quantity, purchase_currency, purchase_cost, current_location_id, current_holder_id,
            condition_status, lifecycle_status, notes
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?,
            ?, 'NGN', NULL, ?, ?,
            'good', 'assigned', ?
        )
    ");

    $asgInsertStmt = $pdo->prepare("
        INSERT INTO asset_assignments (
            assignment_code, asset_id, employee_id, assigned_date, assigned_by_user_id,
            condition_at_issue, agreement_signed, status, remarks
        ) VALUES (
            ?, ?, ?, CURDATE(), 1,
            'good', 0, 'active', ?
        )
    ");

    $movInsertStmt = $pdo->prepare("
        INSERT INTO asset_movements (
            asset_id, from_location_id, to_location_id, to_employee_id, moved_by_user_id,
            reason, notes
        ) VALUES (
            ?, NULL, ?, ?, 1,
            'Initial Deployment', 'Imported from Enhanced Staff Asset Register.xlsx'
        )
    ");

    $tagSeq = 1;
    $importedAssetCount = 0;

    foreach ($rows as $idx => $r) {
        $staffId = (string)($r['Staff ID'] ?? '');
        $staffName = trim((string)($r['Staff Name'] ?? ''));
        $catName = trim((string)($r['Asset Category'] ?? 'Other'));
        $itemName = trim((string)($r['Item / Model'] ?? 'Standard Hardware'));
        $brand = !empty($r['Brand']) ? trim((string)$r['Brand']) : null;
        $model = !empty($r['Model']) ? trim((string)$r['Model']) : null;
        $legacyAssetId = !empty($r['Asset ID']) ? trim((string)$r['Asset ID']) : null;
        $legacyAssetTag = !empty($r['Asset Tag']) ? trim((string)$r['Asset Tag']) : null;
        $serialNumber = !empty($r['Serial Number']) ? trim((string)$r['Serial Number']) : null;
        $locationName = trim((string)($r['Current Location'] ?? 'Open Office'));

        // Reconcile Basirat Shekoni ID
        if ($staffId === '230202') {
            $staffId = '230201';
        }

        $empDbId = $staffIdToDbId[$staffId] ?? ($staffNameToDbId[strtolower($staffName)] ?? null);
        if (!$empDbId) {
            echo "   [WARNING] Skipping row " . ($idx + 1) . ": Unmapped staff ($staffName)\n";
            continue;
        }

        // Determine Category ID
        $catId = $catByName[strtolower($catName)] ?? ($catByName['other'] ?? 1);

        // Determine Location ID
        $locId = $locByName[strtolower($locationName)] ?? $defaultLocId;

        // Generate clean unique Asset Tag
        if (!empty($legacyAssetTag)) {
            $finalTag = $legacyAssetTag;
        } else {
            // Sequential generation e.g. AST-001, AST-002...
            $finalTag = sprintf('AST-%03d', $tagSeq);
            // If exists, bump sequence
            while ($pdo->query("SELECT COUNT(*) FROM assets WHERE asset_tag = '$finalTag'")->fetchColumn() > 0) {
                $tagSeq++;
                $finalTag = sprintf('AST-%03d', $tagSeq);
            }
        }
        $tagSeq++;

        // Insert Asset Record
        $notes = "Excel Row #" . ($r['Record #'] ?? ($idx + 1));
        $assetInsertStmt->execute([
            $finalTag,
            $legacyAssetId,
            $catId,
            $itemName,
            $brand,
            $model,
            $serialNumber,
            1,
            $locId,
            $empDbId,
            $notes
        ]);
        $assetDbId = (int)$pdo->lastInsertId();

        // Create Active Assignment
        $assignmentCode = sprintf('ASG-2026-%04d', $importedAssetCount + 1);
        $asgInsertStmt->execute([
            $assignmentCode,
            $assetDbId,
            $empDbId,
            "Initial assignment migrated from Excel register."
        ]);

        // Create Movement Record
        $movInsertStmt->execute([
            $assetDbId,
            $locId,
            $empDbId
        ]);

        $importedAssetCount++;
        echo "   -> [Row " . ($idx + 1) . "] Tag: $finalTag | $catName ($itemName) -> Assigned to $staffName\n";
    }

    // Commit Transaction
    $pdo->commit();
    echo "\n5. Transaction successfully committed to MySQL!\n";

    echo "================================================================\n";
    echo " MIGRATION SUMMARY REPORT:\n";
    echo "----------------------------------------------------------------\n";
    echo " Total Excel Rows Processed:   " . count($rows) . "\n";
    echo " Unique Employees Created:     " . count($staffRecords) . "\n";
    echo " Total Assets Inserted:        $importedAssetCount\n";
    echo " Active Assignments Created:   $importedAssetCount\n";
    echo " Movement Ledger Entries:      $importedAssetCount\n";
    echo " Deduplications Handled:       Basirat Shekoni (ID 230202 -> 230201)\n";
    echo "================================================================\n";

} catch (Exception $e) {
    $pdo->rollBack();
    die("\nFATAL ERROR during migration: " . $e->getMessage() . "\nTransaction rolled back cleanly.\n");
}
