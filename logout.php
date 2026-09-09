<?php
declare(strict_types=1);

// logout.php - Root Logout Handler
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

logout();
header("Location: " . BASE_URL . "/login");
exit;
