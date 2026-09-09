<?php
require __DIR__ . '/../includes/db.php';
$pdo->exec("
    ALTER TABLE inventory_transactions
    ADD COLUMN IF NOT EXISTS created_by_user_id INT UNSIGNED NULL AFTER reference_id;
");
$pdo->exec("
    UPDATE inventory_transactions SET created_by_user_id = performed_by_user_id WHERE created_by_user_id IS NULL AND performed_by_user_id IS NOT NULL;
");
echo "inventory_transactions updated!\n";
