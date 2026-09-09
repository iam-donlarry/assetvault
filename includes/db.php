<?php
declare(strict_types=1);

// includes/db.php - PDO Connection Factory
require_once __DIR__ . '/../config/config.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    if (ini_get('display_errors')) {
        die("Database Connection Failed: " . $e->getMessage());
    } else {
        die("System Error: Unable to connect to the database. Please contact the administrator.");
    }
}
