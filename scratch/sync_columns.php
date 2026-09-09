<?php
require __DIR__ . '/../includes/db.php';

echo "Adding missing columns to product_models...\n";

$pdo->exec("
    ALTER TABLE product_models
    ADD COLUMN IF NOT EXISTS product_code VARCHAR(50) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS unit_of_measure VARCHAR(20) NOT NULL DEFAULT 'units' AFTER tracking_type,
    ADD COLUMN IF NOT EXISTS specifications TEXT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS min_reorder_level INT UNSIGNED NOT NULL DEFAULT 5 AFTER min_stock_alert,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER specifications;
");

// Populate product_code for any rows missing it
$rows = $pdo->query("SELECT id FROM product_models WHERE product_code IS NULL OR product_code = ''")->fetchAll();
foreach ($rows as $r) {
    $code = sprintf('PRD-%04d', $r['id']);
    $upd = $pdo->prepare("UPDATE product_models SET product_code = ? WHERE id = ?");
    $upd->execute([$code, $r['id']]);
}

// Make product_code NOT NULL and UNIQUE if not yet
try {
    $pdo->exec("ALTER TABLE product_models MODIFY COLUMN product_code VARCHAR(50) NOT NULL;");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE product_models ADD UNIQUE KEY uk_prod_code (product_code);");
} catch (Exception $e) {}

try {
    $pdo->exec("ALTER TABLE product_models ADD UNIQUE KEY uk_canonical_product (category_id, brand, model_name, model_number);");
} catch (Exception $e) {}

echo "product_models columns successfully synced!\n";
