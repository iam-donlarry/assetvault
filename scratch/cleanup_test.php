<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "Cleaning test data...\n";
$pdo->exec("DELETE FROM inventory_transactions WHERE product_id IN (SELECT id FROM product_models WHERE brand LIKE 'Logitech_Test_%')");
$pdo->exec("DELETE FROM asset_assignments WHERE product_id IN (SELECT id FROM product_models WHERE brand LIKE 'Logitech_Test_%')");
$pdo->exec("DELETE FROM inventory_stock WHERE product_id IN (SELECT id FROM product_models WHERE brand LIKE 'Logitech_Test_%')");
$pdo->exec("DELETE FROM product_models WHERE brand LIKE 'Logitech_Test_%'");

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
echo "Assets count: {$cnt}\n";
