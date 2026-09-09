<?php
require __DIR__ . '/../includes/db.php';
$cols = $pdo->query("SHOW COLUMNS FROM inventory_transactions")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(array_column($cols, 'Field'), JSON_PRETTY_PRINT);
