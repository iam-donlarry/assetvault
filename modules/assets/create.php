<?php
// modules/assets/create.php - Register New Physical Serialized Asset
declare(strict_types=1);

$active_page = 'assets_create';
$page_title = 'Register New Serialized Asset';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('assets.create');

$error = '';
$categories = $pdo->query("SELECT * FROM asset_categories ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT * FROM locations ORDER BY name ASC")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name ASC")->fetchAll();

// Fetch serialized products from catalog
$catalogProducts = $pdo->query("
    SELECT p.*, c.name AS category_name, c.is_serialized AS category_is_serialized
    FROM product_models p
    JOIN asset_categories c ON p.category_id = c.id
    WHERE p.tracking_type = 'serialized' AND p.is_active = 1
    ORDER BY c.name ASC, p.brand ASC, p.model_name ASC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please refresh and try again.';
    } else {
        $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $itemName = trim($_POST['item_name'] ?? '');
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $serialNumber = trim($_POST['serial_number'] ?? '');
        $locationId = (int)($_POST['location_id'] ?? 0);
        $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $condition = $_POST['condition_status'] ?? 'good';
        
        // Dual Currency Fields
        $purchaseCurrency = trim($_POST['purchase_currency'] ?? 'NGN');
        $purchaseCost = !empty($_POST['purchase_cost']) ? (float)$_POST['purchase_cost'] : null;
        $secondaryCurrency = trim($_POST['secondary_currency'] ?? 'USD');
        $secondaryCost = !empty($_POST['secondary_cost']) ? (float)$_POST['secondary_cost'] : null;
        $exchangeRate = !empty($_POST['exchange_rate']) ? (float)$_POST['exchange_rate'] : null;

        $purchaseDate = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $warrantyExpiry = !empty($_POST['warranty_expiry_date']) ? $_POST['warranty_expiry_date'] : null;
        $notes = trim($_POST['notes'] ?? '');

        // If product_id was not provided, try to find or auto-create product model
        if (!$productId && $categoryId > 0 && !empty($brand) && !empty($model)) {
            $pm = new ProductManager($pdo);
            // Check if product exists
            $chkStmt = $pdo->prepare("SELECT id FROM product_models WHERE category_id = ? AND LOWER(TRIM(brand)) = LOWER(TRIM(?)) AND LOWER(TRIM(model_name)) = LOWER(TRIM(?))");
            $chkStmt->execute([$categoryId, $brand, $model]);
            $existingPid = $chkStmt->fetchColumn();
            if ($existingPid) {
                $productId = (int)$existingPid;
            } else {
                try {
                    $productId = $pm->create([
                        'category_id'           => $categoryId,
                        'brand'                 => $brand,
                        'model_name'            => $model,
                        'tracking_type'         => 'serialized',
                        'default_purchase_cost' => $purchaseCost,
                        'unit_of_measure'       => 'units'
                    ]);
                } catch (Exception $e) {
                    // Ignore and proceed
                }
            }
        }

        if ($categoryId === 0 || empty($itemName) || $locationId === 0) {
            $error = 'Category, Item Name, and Initial Location are required.';
        } elseif (empty($serialNumber)) {
            $error = 'Serialized equipment requires a valid physical Serial Number. (For bulk un-serialized items, use Bulk Stock Intake).';
        } else {
            // Check serial number uniqueness
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE serial_number = ?");
            $checkStmt->execute([$serialNumber]);
            if ($checkStmt->fetchColumn() > 0) {
                $error = "The serial number '$serialNumber' is already registered to another physical unit.";
            }

            if (empty($error)) {
                try {
                    $pdo->beginTransaction();

                    // Generate next asset tag
                    $tagCount = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
                    $nextSeq = $tagCount + 1;
                    $assetTag = sprintf('AST-%03d', $nextSeq);
                    while ($pdo->query("SELECT COUNT(*) FROM assets WHERE asset_tag = '$assetTag'")->fetchColumn() > 0) {
                        $nextSeq++;
                        $assetTag = sprintf('AST-%03d', $nextSeq);
                    }

                    // Insert serialized asset
                    $stmt = $pdo->prepare("
                        INSERT INTO assets (
                            asset_tag, product_id, category_id, item_name, brand, model, serial_number,
                            quantity, purchase_currency, purchase_cost, secondary_currency, secondary_cost, exchange_rate,
                            purchase_date, supplier_id, current_location_id, condition_status, lifecycle_status,
                            warranty_expiry_date, notes
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?,
                            1, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, 'available',
                            ?, ?
                        )
                    ");
                    $stmt->execute([
                        $assetTag, $productId, $categoryId, $itemName, $brand ?: null, $model ?: null, $serialNumber ?: null,
                        $purchaseCurrency, $purchaseCost, $secondaryCurrency, $secondaryCost, $exchangeRate,
                        $purchaseDate, $supplierId, $locationId, $condition,
                        $warrantyExpiry, $notes ?: null
                    ]);
                    $assetId = (int)$pdo->lastInsertId();

                    // Insert Initial Movement Entry
                    $movStmt = $pdo->prepare("
                        INSERT INTO asset_movements (asset_id, from_location_id, to_location_id, moved_by_user_id, reason, notes)
                        VALUES (?, NULL, ?, ?, 'Initial Registration', 'Serialized asset registered into custody inventory')
                    ");
                    $movStmt->execute([$assetId, $locationId, $_SESSION['user_id']]);

                    // Audit Log
                    logAudit('asset.create', 'asset', $assetId, null, [
                        'asset_tag' => $assetTag,
                        'product_id' => $productId,
                        'item_name' => $itemName,
                        'serial_number' => $serialNumber
                    ]);

                    $pdo->commit();
                    flashMessage('success', "Serialized Asset {$assetTag} ({$itemName}) registered successfully!");
                    redirect("assets/view/{$assetTag}");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Failed to register asset: ' . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="max-width: 920px; margin: 0 auto;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
        <div>
            <h1 style="font-size: 1.5rem; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="barcode" style="color: var(--primary); width: 26px; height: 26px;"></i>
                <span>Register New Serialized Asset</span>
            </h1>
            <p style="color: var(--text-secondary); font-size: 0.875rem; margin-bottom: 0;">Add an individual, uniquely tracked physical unit with serial number into inventory.</p>
        </div>
        <a href="<?= BASE_URL ?>/assets" class="btn btn-outline btn-sm">
            <i data-lucide="arrow-left"></i> Back to Register
        </a>
    </div>

    <!-- Bulk Item Guidance Alert -->
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: var(--radius-sm); padding: 0.85rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <i data-lucide="info" style="color: #16a34a; width: 22px; height: 22px; flex-shrink: 0;"></i>
            <span style="font-size: 0.875rem; color: #15803d;">
                <strong>Tracking bulk supplies or peripherals?</strong> Mice, keyboards, cables, and un-serialized accessories should be received as bulk quantity inventory rather than individual serialized assets.
            </span>
        </div>
        <a href="<?= BASE_URL ?>/inventory" class="btn btn-sm" style="background: #16a34a; color: #ffffff; white-space: nowrap;">
            Go to Bulk Stock
        </a>
    </div>

    <?php if (!empty($error)): ?>
        <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem;">
            <i data-lucide="alert-circle" style="width: 18px; height: 18px;"></i>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/assets/create" class="card" style="padding: 2rem;">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

        <!-- Section 1: Catalog Selection & Specs -->
        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary); display: flex; align-items: center; gap: 0.45rem;">
            <i data-lucide="boxes" style="width: 18px; height: 18px;"></i>
            <span>1. Product Catalog & Hardware Specifications</span>
        </h3>

        <div style="margin-bottom: 1.25rem;">
            <label class="form-label" for="product_id">Select Catalog Product Model (Recommended)</label>
            <select name="product_id" id="product_id" class="form-control" onchange="handleCatalogSelect(this)">
                <option value="">-- Choose from Master Catalog (Auto-fills specs) --</option>
                <?php foreach ($catalogProducts as $cp): ?>
                <option value="<?= $cp['id'] ?>" 
                        data-category="<?= $cp['category_id'] ?>" 
                        data-brand="<?= e($cp['brand']) ?>" 
                        data-model="<?= e($cp['model_name']) ?>" 
                        data-modelnum="<?= e($cp['model_number'] ?? '') ?>"
                        data-cost="<?= $cp['default_purchase_cost'] ?? '' ?>">
                    <?= e($cp['brand']) ?> <?= e($cp['model_name']) ?> (<?= e($cp['category_name']) ?> - <?= e($cp['product_code']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <small style="color: var(--text-secondary); font-size: 0.75rem;">Selecting a catalog model binds this unit to the canonical hardware catalog and standardizes reporting.</small>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label" for="category_id">Asset Category <span style="color: var(--danger);">*</span></label>
                <select name="category_id" id="category_id" class="form-control" required>
                    <option value="">Select Category...</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>">
                        <?= e($cat['name']) ?> (<?= $cat['is_serialized'] ? 'Serialized' : 'Consumable' ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="item_name">Item / Display Name <span style="color: var(--danger);">*</span></label>
                <input type="text" name="item_name" id="item_name" class="form-control" placeholder="e.g. Dell Latitude 5420 Laptop" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 1.25rem; margin-bottom: 1.5rem;">
            <div class="form-group">
                <label class="form-label" for="brand">Brand / Manufacturer</label>
                <input type="text" name="brand" id="brand" class="form-control" placeholder="e.g. Dell, HP, Apple">
            </div>

            <div class="form-group">
                <label class="form-label" for="model">Model Name / Number</label>
                <input type="text" name="model" id="model" class="form-control" placeholder="e.g. Latitude 5420 Core i7">
            </div>

            <div class="form-group">
                <label class="form-label" for="serial_number">Physical Serial Number <span style="color: var(--danger);">*</span></label>
                <input type="text" name="serial_number" id="serial_number" class="form-control" placeholder="e.g. HM4RK13 or SN-90219" required>
                <small style="color: var(--text-secondary); font-size: 0.75rem;">Must be unique across all serialized equipment.</small>
            </div>
        </div>

        <!-- Section 2: Dual Currency & Financials -->
        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary); display: flex; align-items: center; gap: 0.45rem;">
            <i data-lucide="wallet" style="width: 18px; height: 18px;"></i>
            <span>2. Dual-Currency Financials & Procurement</span>
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1.5fr 1fr 1.5fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label" for="purchase_currency">Purchase Currency</label>
                <select name="purchase_currency" id="purchase_currency" class="form-control">
                    <option value="NGN" selected>NGN (₦)</option>
                    <option value="USD">USD ($)</option>
                    <option value="GBP">GBP (£)</option>
                    <option value="EUR">EUR (€)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="purchase_cost">Purchase Cost</label>
                <input type="number" step="0.01" name="purchase_cost" id="purchase_cost" class="form-control" placeholder="0.00">
            </div>

            <div class="form-group">
                <label class="form-label" for="secondary_currency">Reporting Currency</label>
                <select name="secondary_currency" id="secondary_currency" class="form-control">
                    <option value="USD" selected>USD ($)</option>
                    <option value="NGN">NGN (₦)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="secondary_cost">Secondary Cost</label>
                <input type="number" step="0.01" name="secondary_cost" id="secondary_cost" class="form-control" placeholder="0.00">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem;">
            <div class="form-group">
                <label class="form-label" for="exchange_rate">Exchange Rate (NGN / USD)</label>
                <input type="number" step="0.0001" name="exchange_rate" id="exchange_rate" class="form-control" placeholder="1500.00">
            </div>

            <div class="form-group">
                <label class="form-label" for="purchase_date">Purchase Date</label>
                <input type="date" name="purchase_date" id="purchase_date" class="form-control">
            </div>

            <div class="form-group">
                <label class="form-label" for="warranty_expiry_date">Warranty Expiration</label>
                <input type="date" name="warranty_expiry_date" id="warranty_expiry_date" class="form-control">
            </div>
        </div>

        <!-- Section 3: Initial Location & Supplier -->
        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary); display: flex; align-items: center; gap: 0.45rem;">
            <i data-lucide="map-pin" style="width: 18px; height: 18px;"></i>
            <span>3. Storage Location & Intake Condition</span>
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label" for="location_id">Initial Location <span style="color: var(--danger);">*</span></label>
                <select name="location_id" id="location_id" class="form-control" required>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= strtolower($loc['name']) === 'store' ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?> (<?= e($loc['floor']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="condition_status">Intake Condition</label>
                <select name="condition_status" id="condition_status" class="form-control">
                    <option value="new" selected>New (Factory Sealed)</option>
                    <option value="good">Good (Fully Operational)</option>
                    <option value="fair">Fair (Working with Cosmetic Wear)</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label" for="supplier_id">Procurement Supplier</label>
                <select name="supplier_id" id="supplier_id" class="form-control">
                    <option value="">Unknown / Internal Stock</option>
                    <?php foreach ($suppliers as $sup): ?>
                    <option value="<?= $sup['id'] ?>"><?= e($sup['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label class="form-label" for="notes">Administrative Notes</label>
            <textarea name="notes" id="notes" class="form-control" rows="2" placeholder="Optional notes regarding asset acquisition or accessories"></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 1rem; border-top: 1px solid var(--border); padding-top: 1.25rem;">
            <a href="<?= BASE_URL ?>/assets" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                <i data-lucide="check"></i> Register Serialized Unit
            </button>
        </div>
    </form>
</div>

<script>
function handleCatalogSelect(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) return;

    const catId = opt.getAttribute('data-category');
    const brand = opt.getAttribute('data-brand');
    const model = opt.getAttribute('data-model');
    const cost = opt.getAttribute('data-cost');

    if (catId) document.getElementById('category_id').value = catId;
    if (brand) document.getElementById('brand').value = brand;
    if (model) document.getElementById('model').value = model;
    if (brand && model) {
        document.getElementById('item_name').value = brand + ' ' + model;
    }
    if (cost && cost !== '') {
        const costInput = document.getElementById('purchase_cost');
        if (costInput && (!costInput.value || costInput.value == '0.00')) {
            costInput.value = parseFloat(cost).toFixed(2);
        }
    }
}
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
