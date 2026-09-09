<?php
declare(strict_types=1);

// config/config.php - AssetVault Configuration

// 1. Error Reporting (Auto-detect environment)
$sapi = php_sapi_name();
$server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';
$is_local = ($sapi === 'cli' || $server_name === 'localhost' || $server_name === '127.0.0.1');

if ($is_local) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// 2. Timezone
date_default_timezone_set('Africa/Lagos');

// 3. Application Identity
define('APP_NAME', 'AssetVault');
define('APP_VERSION', '1.0.0');
define('COMPANY_NAME', 'Baye Business Solutions');
define('BRAND_COLOR', '#12b1b0');

// 4. Dynamic Base URL Detection
$envAppUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '');
if (!empty($envAppUrl)) {
    define('BASE_URL', rtrim($envAppUrl, '/'));
} else {
    $protocol = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) || 
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir = rtrim($scriptDir, '/' . DIRECTORY_SEPARATOR);
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '.' || $scriptDir === '/') $scriptDir = '';
    
    define('BASE_URL', $protocol . '://' . $host . $scriptDir);
}

// 5. Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'assetvault');
define('DB_CHARSET', 'utf8mb4');

// 6. Dual Currency Configuration
define('DEFAULT_CURRENCY', 'NGN');
define('CURRENCY_SYMBOL', '₦');
define('SECONDARY_CURRENCY', 'USD');
define('SECONDARY_CURRENCY_SYMBOL', '$');
define('SUPPORTED_CURRENCIES', json_encode(['NGN', 'USD', 'GBP', 'EUR']));
define('DEFAULT_EXCHANGE_RATE', 1500.00);

// 7. Security & Session
define('SESSION_LIFETIME', 18 * 3600); // 18 hours
define('CSRF_TOKEN_SECRET', 'assetvault_bbsl_secure_token_key');

// 8. Class Autoloader
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/../classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
