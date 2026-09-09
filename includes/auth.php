<?php
declare(strict_types=1);

// includes/auth.php - Authentication & RBAC Engine
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
            ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        session_start();
    }
}

/**
 * Attempt User Login
 * Accepts username or email
 */
function login(string $identifier, string $password): bool|string {
    global $pdo;
    startSession();
    
    $stmt = $pdo->prepare("
        SELECT u.*, r.slug AS role_slug, r.name AS role_name, e.full_name AS employee_name
        FROM users u
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN employees e ON u.employee_id = e.id
        WHERE (u.email = ? OR u.username = ?)
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        return "Invalid username/email or password.";
    }

    if (!password_verify($password, $user['password_hash'])) {
        return "Invalid username/email or password.";
    }

    if ((int)$user['is_active'] !== 1) {
        return "Your account has been deactivated. Please contact your system administrator.";
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['role_id'] = (int)$user['role_id'];
    $_SESSION['role_slug'] = $user['role_slug'] ?? 'staff';
    $_SESSION['role_name'] = $user['role_name'] ?? 'Staff Custodian';
    $_SESSION['employee_id'] = $user['employee_id'] ? (int)$user['employee_id'] : null;
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();

    // Cache user permissions in session for high performance
    loadUserPermissions((int)$user['id']);

    // Update last_login timestamp
    try {
        $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $upd->execute([$user['id']]);
    } catch (Exception $e) {}

    // Record login in audit log
    logAudit('user.login', 'user', (int)$user['id'], null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

    return true;
}

/**
 * Cache permissions in session array
 */
function loadUserPermissions(int $userId): void {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.slug
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        JOIN users u ON u.role_id = rp.role_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $_SESSION['user_permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Check if current user has a specific permission
 */
function hasPermission(string $permissionSlug): bool {
    startSession();
    if (!isLoggedIn()) return false;

    // Super Admin has all permissions unconditionally
    if (($_SESSION['role_slug'] ?? '') === 'super_admin') {
        return true;
    }

    $perms = $_SESSION['user_permissions'] ?? [];
    return in_array($permissionSlug, $perms, true);
}

/**
 * Check if user is currently logged in
 */
function isLoggedIn(): bool {
    startSession();
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        $elapsed = time() - ($_SESSION['login_time'] ?? 0);
        if ($elapsed > SESSION_LIFETIME) {
            logout();
            return false;
        }
        return true;
    }
    return false;
}

/**
 * Terminate Session & Logout
 */
function logout(): void {
    startSession();
    if (isset($_SESSION['user_id'])) {
        logAudit('user.logout', 'user', (int)$_SESSION['user_id'], null, null);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Guard page access - Require logged in user
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse(['error' => 'Unauthenticated', 'redirect' => BASE_URL . '/login'], 401);
        }
        redirect('login');
    }
}

/**
 * Guard page access - Require permission key
 */
function requirePermission(string $permissionSlug): void {
    requireLogin();
    if (!hasPermission($permissionSlug)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            jsonResponse(['error' => 'Forbidden - You lack permission: ' . $permissionSlug], 403);
        }
        flashMessage('error', 'Access denied. You do not have permission to access that resource.');
        redirect('dashboard');
    }
}

/**
 * Audit Logger Helper
 */
function logAudit(string $action, string $entityType, int $entityId, ?array $oldValues = null, ?array $newValues = null): void {
    global $pdo;
    try {
        startSession();
        $userId = $_SESSION['user_id'] ?? null;
        $userName = $_SESSION['user_name'] ?? 'System';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, user_name, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $userName,
            $action,
            $entityType,
            $entityId,
            $oldValues ? json_encode($oldValues, JSON_UNESCAPED_SLASHES) : null,
            $newValues ? json_encode($newValues, JSON_UNESCAPED_SLASHES) : null,
            $ip,
            $userAgent
        ]);
    } catch (Exception $e) {
        error_log("Audit log failure: " . $e->getMessage());
    }
}
