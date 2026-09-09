<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== assets columns ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM assets")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "{$c['Field']}: {$c['Type']} (Null: {$c['Null']}, Default: {$c['Default']})\n";
}
