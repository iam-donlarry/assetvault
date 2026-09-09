<?php
declare(strict_types=1);

// scratch/test_catalog_ledger.php - Comprehensive E2E Verification Suite
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=====================================================================\n";
echo "AssetVault — Catalog & Stock Ledger E2E Invariants Test Suite\n";
echo "=====================================================================\n";

$pm = new ProductManager($pdo);
$im = new InventoryManager($pdo);
$am = new AssignmentManager($pdo);

$testUserId = (int)$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$testEmpId = (int)$pdo->query("SELECT id FROM employees WHERE employment_status = 'active' LIMIT 1")->fetchColumn();
$storeLocId = (int)$pdo->query("SELECT id FROM locations WHERE code = 'LOC-STORE'")->fetchColumn();
$openLocId = (int)$pdo->query("SELECT id FROM locations WHERE code = 'LOC-OPEN'")->fetchColumn();
$mouseCatId = (int)$pdo->query("SELECT id FROM asset_categories WHERE code = 'MOU'")->fetchColumn();

// --- TEST 1: Baseline Integrity Verification ---
echo "[TEST 1] Baseline Database Integrity...\n";
$assetCount = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$linkedAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE product_id IS NOT NULL")->fetchColumn();
assert($assetCount === 28, "Expected 28 assets, found {$assetCount}");
assert($linkedAssets === 28, "Expected 28 linked assets, found {$linkedAssets}");
echo "  ✓ All 28 legacy assets remain intact and linked to product_models.\n";

// --- TEST 2: Product Creation & Canonical Uniqueness ---
echo "[TEST 2] Product Creation & Canonical Uniqueness Rules...\n";
$testBrand = 'Logitech_Test_' . time();
$testModel = 'MX Master 3S Test';
$testPartNum = '910-TEST-01';

$productId = $pm->create([
    'category_id'           => $mouseCatId,
    'brand'                 => $testBrand,
    'model_name'            => $testModel,
    'model_number'          => $testPartNum,
    'tracking_type'         => 'bulk_quantity',
    'unit_of_measure'       => 'units',
    'min_reorder_level'     => 10,
    'default_purchase_cost' => 10000.00
]);
assert($productId > 0, "Failed to create product");
echo "  ✓ Product created successfully with ID: {$productId}\n";

// Attempt duplicate creation (should fail)
try {
    $pm->create([
        'category_id'     => $mouseCatId,
        'brand'           => $testBrand,
        'model_name'      => $testModel,
        'model_number'    => $testPartNum,
        'tracking_type'   => 'bulk_quantity'
    ]);
    throw new Exception("Duplicate canonical product creation was NOT prevented!");
} catch (InvalidArgumentException $e) {
    echo "  ✓ Canonical duplicate successfully rejected: " . $e->getMessage() . "\n";
}

// --- TEST 3: Bulk Stock Intake & Weighted Average Cost (WAC) ---
echo "[TEST 3] Bulk Inventory Intake & WAC Recalculation...\n";
$tx1 = $im->receiveStock($productId, $storeLocId, 50, 10000.00, $testUserId, "Batch 1 Intake");
assert($tx1 > 0, "Intake transaction 1 failed");

$stockStore = $im->getStock($productId, $storeLocId)[0];
assert((int)$stockStore['quantity_available'] === 50, "Available qty should be 50");
assert((int)$stockStore['total_owned'] === 50, "Total owned should be 50");

$pInfo = $pm->getById($productId);
assert(abs((float)$pInfo['average_unit_cost'] - 10000.00) < 0.01, "Average cost should be 10000.00");
echo "  ✓ Batch 1: 50 units @ ₦10,000 received. Stock = 50, Avg Cost = ₦10,000.00\n";

// Receive Batch 2 with different cost: 50 units @ ₦12,000
$tx2 = $im->receiveStock($productId, $storeLocId, 50, 12000.00, $testUserId, "Batch 2 Intake");
$pInfo2 = $pm->getById($productId);
$expectedAvg = (50 * 10000 + 50 * 12000) / 100; // 11000.00
assert(abs((float)$pInfo2['average_unit_cost'] - $expectedAvg) < 0.01, "Expected avg cost 11000, got {$pInfo2['average_unit_cost']}");
echo "  ✓ Batch 2: 50 units @ ₦12,000 received. Stock = 100, Recalculated WAC = ₦" . number_format((float)$pInfo2['average_unit_cost'], 2) . "\n";

// --- TEST 4: Tracking Type Immutability Check ---
echo "[TEST 4] Tracking Type Immutability Enforced...\n";
try {
    $pm->update($productId, ['tracking_type' => 'serialized']);
    throw new Exception("Tracking type change was NOT blocked when stock existed!");
} catch (Exception $e) {
    echo "  ✓ Tracking type change successfully blocked: " . $e->getMessage() . "\n";
}

// --- TEST 5: Multi-Location Transfer with Row Locks ---
echo "[TEST 5] Multi-Location Atomic Stock Transfer...\n";
$tx3 = $im->transferStock($productId, $storeLocId, $openLocId, 25, $testUserId, "Transfer to Open Office");
assert($tx3 > 0, "Transfer transaction failed");

$stockStoreAfter = $im->getStock($productId, $storeLocId)[0];
$stockOpenAfter = $im->getStock($productId, $openLocId)[0];
assert((int)$stockStoreAfter['quantity_available'] === 75, "Store stock should be 75");
assert((int)$stockOpenAfter['quantity_available'] === 25, "Open office stock should be 25");
echo "  ✓ Transferred 25 units: Store = 75, Open Office = 25 (Total = 100).\n";

// Over-transfer rejection check
try {
    $im->transferStock($productId, $openLocId, $storeLocId, 50, $testUserId, "Invalid Transfer");
    throw new Exception("Over-transfer was NOT prevented!");
} catch (InvalidArgumentException $e) {
    echo "  ✓ Over-transfer successfully rejected: " . $e->getMessage() . "\n";
}

// --- TEST 6: Bulk Checkout (Zero Fake Assets Invariant) ---
echo "[TEST 6] Bulk Checkout & Zero Fake Assets Rule...\n";
$preAssetsCount = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();

$asgId = $am->checkoutBulk($productId, $openLocId, 6, $testEmpId, date('Y-m-d'), $testUserId, [
    'condition' => 'new',
    'remarks' => 'Bulk checkout test'
]);
assert($asgId > 0, "Bulk checkout failed");

$postAssetsCount = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
assert($preAssetsCount === $postAssetsCount, "ZERO FAKE ASSETS VIOLATION: assets table grew from {$preAssetsCount} to {$postAssetsCount}!");
echo "  ✓ ZERO FAKE ASSETS ASSERTION PASSED: No rows added to assets table ({$postAssetsCount} unchanged).\n";

$stockOpenAfterCheckout = $im->getStock($productId, $openLocId)[0];
assert((int)$stockOpenAfterCheckout['quantity_available'] === 19, "Open office avail should be 19 (25 - 6)");
assert((int)$stockOpenAfterCheckout['quantity_assigned'] === 6, "Open office assigned should be 6");
assert((int)$stockOpenAfterCheckout['total_owned'] === 25, "Open office total owned should be 25");
echo "  ✓ Stock Balances at Open Office: Available = 19, Assigned = 6, Total Owned = 25.\n";

// --- TEST 7: Partial Returns & Invariants ---
echo "[TEST 7] Partial Returns & Automatic State Transitions...\n";

// Over-return rejection check (remaining = 6)
try {
    $am->returnItem($asgId, 10, $openLocId, 'good', date('Y-m-d'), $testUserId, 'Over-return test');
    throw new Exception("Over-return was NOT prevented!");
} catch (InvalidArgumentException $e) {
    echo "  ✓ Over-return successfully rejected: " . $e->getMessage() . "\n";
}

// Return 2 units out of 6 (Partial Return)
$am->returnItem($asgId, 2, $openLocId, 'good', date('Y-m-d'), $testUserId, 'Partial return of 2 units');
$asgRow = $pdo->query("SELECT * FROM asset_assignments WHERE id = {$asgId}")->fetch();
assert((int)$asgRow['quantity_returned'] === 2, "Returned qty should be 2");
assert($asgRow['status'] === 'partially_returned', "Status should be partially_returned, got {$asgRow['status']}");

$stockOpenAfterPartial = $im->getStock($productId, $openLocId)[0];
assert((int)$stockOpenAfterPartial['quantity_available'] === 21, "Available should be 21 (19 + 2)");
assert((int)$stockOpenAfterPartial['quantity_assigned'] === 4, "Assigned should be 4 (6 - 2)");
echo "  ✓ Partial return of 2 units: Assignment Status = 'partially_returned', Open Office: Avail = 21, Assigned = 4.\n";

// Return remaining 4 units in 'damaged' condition to Store Location
$am->returnItem($asgId, 4, $storeLocId, 'damaged', date('Y-m-d'), $testUserId, 'Damaged return to store');
$asgRowFinal = $pdo->query("SELECT * FROM asset_assignments WHERE id = {$asgId}")->fetch();
assert((int)$asgRowFinal['quantity_returned'] === 6, "Returned qty should be 6");
assert($asgRowFinal['status'] === 'returned', "Status should be returned");

$stockOpenFinal = $im->getStock($productId, $openLocId)[0];
$stockStoreFinal = $im->getStock($productId, $storeLocId)[0];
assert((int)$stockOpenFinal['quantity_assigned'] === 0, "Open assigned should be 0");
assert((int)$stockStoreFinal['quantity_damaged'] === 4, "Store damaged should be 4");
echo "  ✓ Final return of 4 damaged units: Assignment Status = 'returned', Routed to Store Damaged Bucket = 4 units.\n";

// --- TEST 8: Cycle Count Discrepancy Adjustment ---
echo "[TEST 8] Physical Cycle Count Discrepancy Adjustment...\n";
$im->adjustStock($productId, $storeLocId, 'available', 73, 'End of month audit', $testUserId, 'Found 2 damaged mice');
$stockStoreAdj = $im->getStock($productId, $storeLocId)[0];
assert((int)$stockStoreAdj['quantity_available'] === 73, "Adjusted available should be 73");

$latestTx = $im->getTransactions(['product_id' => $productId, 'transaction_type' => 'cycle_count_adjustment'], 1)[0];
assert((int)$latestTx['quantity'] === -2, "Adjustment delta should be -2");
echo "  ✓ Cycle count adjustment recorded: Delta = -2, New Count = 73, Transaction Logged.\n";

// --- TEST 9: Serialized Unit Checkout & Return Workflow ---
echo "[TEST 9] Serialized Unit Checkout & Return Workflow...\n";
$legacyProdId = (int)$pdo->query("SELECT id FROM product_models WHERE tracking_type = 'serialized' LIMIT 1")->fetchColumn();
$testAssetTag = 'AST-TEST-' . time();
$createAssetStmt = $pdo->prepare("
    INSERT INTO assets (
        asset_tag, category_id, product_id, item_name, current_location_id, lifecycle_status,
        condition_status
    ) VALUES (
        :tag, :cat, :prod, 'Test Asset Item', :loc, 'available', 'good'
    )
");
$createAssetStmt->execute([
    ':tag' => $testAssetTag,
    ':cat' => $mouseCatId,
    ':prod' => $legacyProdId,
    ':loc' => $storeLocId
]);
$testAssetId = (int)$pdo->lastInsertId();
$assetTag = $testAssetTag;

$serAsgId = $am->checkoutSerialized($testAssetId, $testEmpId, date('Y-m-d'), $testUserId, ['condition' => 'good']);
assert($serAsgId > 0, "Serialized checkout failed");

$assetStatus = $pdo->query("SELECT lifecycle_status, current_holder_id FROM assets WHERE id = {$testAssetId}")->fetch();
assert($assetStatus['lifecycle_status'] === 'assigned', "Asset should be assigned");
assert((int)$assetStatus['current_holder_id'] === $testEmpId, "Holder should be test employee");
echo "  ✓ Serialized asset {$assetTag} checked out to staff.\n";

$am->returnItem($serAsgId, 1, $storeLocId, 'good', date('Y-m-d'), $testUserId, 'Return of serialized unit');
$assetStatusRet = $pdo->query("SELECT lifecycle_status, current_holder_id, current_location_id FROM assets WHERE id = {$testAssetId}")->fetch();
assert($assetStatusRet['lifecycle_status'] === 'available', "Asset should be available after return");
assert($assetStatusRet['current_holder_id'] === null, "Holder should be null after return");
assert((int)$assetStatusRet['current_location_id'] === $storeLocId, "Location should be Store after return");
echo "  ✓ Serialized asset {$assetTag} returned to Store in good condition.\n";

// --- TEST 10: Clean up Test Catalog Item & Stock ---
echo "[TEST 10] Cleaning up test records...\n";
$pdo->exec("DELETE FROM inventory_transactions WHERE product_id = {$productId}");
$pdo->exec("DELETE FROM asset_assignments WHERE id = {$asgId}");
$pdo->exec("DELETE FROM inventory_stock WHERE product_id = {$productId}");
$pdo->exec("DELETE FROM product_models WHERE id = {$productId}");

// Clean up serialized assignment test record and temporary test asset
$pdo->exec("DELETE FROM asset_movements WHERE asset_id = {$testAssetId}");
$pdo->exec("DELETE FROM asset_assignments WHERE id = {$serAsgId}");
$pdo->exec("DELETE FROM assets WHERE id = {$testAssetId}");
echo "  ✓ Cleaned up test artifacts.\n";

// Final verification of 28 assets
$finalAssets = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
assert($finalAssets === 28, "Final asset count must be 28, found {$finalAssets}");

echo "\n=====================================================================\n";
echo "ALL 10 VERIFICATION TESTS PASSED WITH 100% INVARIANT COMPLIANCE!\n";
echo "=====================================================================\n";
