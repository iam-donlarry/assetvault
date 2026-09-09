<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "Altering inventory_transactions enum...\n";
$pdo->exec("
    ALTER TABLE `inventory_transactions` 
    MODIFY COLUMN `transaction_type` ENUM(
        'intake', 'checkout', 'return', 'transfer', 'adjustment', 'damage', 'loss', 'disposal',
        'purchase_receive', 'staff_checkout', 'staff_return', 'cycle_count_adjustment', 'damage_write_off', 'location_transfer'
    ) NOT NULL
");
echo "Done!\n";
