<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== DATABASE AUDIT: TABLES & COLUMNS ===\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$dbSchema = [];
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW FULL COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    $dbSchema[$t] = [];
    foreach ($cols as $c) {
        $dbSchema[$t][$c['Field']] = [
            'type' => $c['Type'],
            'null' => $c['Null'],
            'default' => $c['Default'],
            'key' => $c['Key'],
            'extra' => $c['Extra']
        ];
    }
}

file_put_contents(__DIR__ . '/live_db_schema.json', json_encode($dbSchema, JSON_PRETTY_PRINT));
echo "Dumped " . count($dbSchema) . " tables to scratch/live_db_schema.json\n";

echo "\n=== PERMISSIONS IN DB ===\n";
$perms = $pdo->query("SELECT module, name, slug FROM permissions ORDER BY module, slug")->fetchAll(PDO::FETCH_ASSOC);
foreach ($perms as $p) {
    echo "  [{$p['module']}] {$p['slug']} => {$p['name']}\n";
}

echo "\n=== ROLES IN DB ===\n";
$roles = $pdo->query("SELECT id, name, slug FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($roles as $r) {
    $pCount = $pdo->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = {$r['id']}")->fetchColumn();
    echo "  Role #{$r['id']}: {$r['name']} ({$r['slug']}) - {$pCount} permissions\n";
}

echo "\n=== USERS IN DB ===\n";
$users = $pdo->query("SELECT id, username, email, full_name, role_id, is_active FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "  User #{$u['id']}: {$u['username']} ({$u['email']}), Full Name: {$u['full_name']}, Role: {$u['role_id']}, Active: {$u['is_active']}\n";
}
