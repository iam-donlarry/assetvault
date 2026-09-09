<?php
declare(strict_types=1);

// index.php - Front Controller & Clean URL Router
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

// 1. Determine Route Path from URI
$request_uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = dirname($script_name);

if ($base_dir !== '/' && $base_dir !== '\\' && strpos($request_uri, $base_dir) === 0) {
    $path = substr($request_uri, strlen($base_dir));
} else {
    $path = $request_uri;
}

$path = trim($path, '/');

// Handle index.php in URI
if (strpos($path, 'index.php') === 0) {
    $path = $_GET['page'] ?? '';
}

// Strip .php extension if present
if (str_ends_with($path, '.php')) {
    $path = substr($path, 0, -4);
}

// 2. Default Root Route
if (empty($path)) {
    if (isLoggedIn()) {
        header("Location: " . BASE_URL . "/dashboard");
    } else {
        header("Location: " . BASE_URL . "/login");
    }
    exit;
}

// 3. Direct Root Pages Map
$root_pages = [
    'login' => 'login.php',
    'logout' => 'logout.php',
    'dashboard' => 'dashboard.php',
];

if (isset($root_pages[$path]) && file_exists(__DIR__ . '/' . $root_pages[$path])) {
    include __DIR__ . '/' . $root_pages[$path];
    exit;
}

// 4. API & AJAX Routing
if (strpos($path, 'api/') === 0) {
    $apiFile = __DIR__ . '/' . $path . '.php';
    if (file_exists($apiFile)) {
        include $apiFile;
        exit;
    }
}

if (strpos($path, 'ajax/') === 0) {
    $ajaxFile = __DIR__ . '/' . $path . '.php';
    if (file_exists($ajaxFile)) {
        include $ajaxFile;
        exit;
    }
}

// 5. Clean Module Routing Map
$routes = [
    // Assets & Inventory
    'catalog'                => 'modules/catalog/index.php',
    'assets'                 => 'modules/assets/index.php',
    'assets/create'          => 'modules/assets/create.php',
    'inventory'              => 'modules/inventory/index.php',
    
    // Assignments & Custody
    'assignments'            => 'modules/assignments/index.php',
    'assignments/checkout'   => 'modules/assignments/create.php',
    'movements'              => 'modules/movements/index.php',
    'maintenance'            => 'modules/maintenance/index.php',
    
    // Staff & Provisioning
    'staff'                  => 'modules/staff/index.php',
    'onboarding'             => 'modules/onboarding/index.php',
    'offboarding'            => 'modules/offboarding/index.php',
    
    // Reference Data & Settings
    'categories'             => 'modules/settings/categories.php',
    'suppliers'              => 'modules/suppliers/index.php',
    'locations'              => 'modules/locations/index.php',
    'reports'                => 'modules/reports/index.php',
    'users'                  => 'modules/settings/users.php',
    'settings'               => 'modules/settings/index.php',
    'audit'                  => 'modules/audit/index.php',
];

// Check dynamic routes (e.g. assets/view/AST-001 or staff/view/240601)
if (preg_match('#^assets/view/([^/]+)$#', $path, $matches)) {
    $_GET['tag'] = $matches[1];
    if (file_exists(__DIR__ . '/modules/assets/view.php')) {
        include __DIR__ . '/modules/assets/view.php';
        exit;
    }
}

if (preg_match('#^staff/view/([^/]+)$#', $path, $matches)) {
    $_GET['staff_id'] = $matches[1];
    if (file_exists(__DIR__ . '/modules/staff/view.php')) {
        include __DIR__ . '/modules/staff/view.php';
        exit;
    }
}

if (preg_match('#^offboarding/clearance/([^/]+)$#', $path, $matches)) {
    $_GET['staff_id'] = $matches[1];
    if (file_exists(__DIR__ . '/modules/offboarding/clearance.php')) {
        include __DIR__ . '/modules/offboarding/clearance.php';
        exit;
    }
}

// Match static route map
if (isset($routes[$path])) {
    $targetFile = __DIR__ . '/' . $routes[$path];
    if (file_exists($targetFile)) {
        include $targetFile;
        exit;
    }
}

// 6. Fallback 404
http_response_code(404);
$page_title = 'Page Not Found';
include __DIR__ . '/includes/header.php';
?>
<div class="card" style="text-align: center; padding: 4rem 2rem;">
    <div style="font-size: 3rem; color: var(--primary-color); font-weight: 800; margin-bottom: 0.5rem;">404</div>
    <h2 style="margin-bottom: 0.5rem;">Page Not Found</h2>
    <p style="color: var(--text-secondary); max-width: 450px; margin: 0 auto 1.5rem auto;">
        The resource or route you requested (<code>/<?= e($path) ?></code>) does not exist or has been moved.
    </p>
    <a href="<?= BASE_URL ?>/dashboard" class="btn btn-primary" style="display: inline-flex;">
        <i data-lucide="arrow-left"></i> Return to Dashboard
    </a>
</div>
<?php
include __DIR__ . '/includes/footer.php';
exit;
