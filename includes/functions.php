<?php
declare(strict_types=1);

// includes/functions.php - Global Helper Utilities

/**
 * Sanitize string input
 */
function sanitize(?string $data): string {
    if ($data === null) return '';
    return htmlspecialchars(trim($data), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Quick HTML escape helper for view templates
 */
function e(?string $string): string {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Format monetary amount with currency symbol
 */
function formatMoney(?float $amount, string $currency = DEFAULT_CURRENCY): string {
    if ($amount === null) return '—';
    
    $symbols = [
        'NGN' => '₦',
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€'
    ];
    
    $sym = $symbols[$currency] ?? $currency . ' ';
    return $sym . number_format($amount, 2);
}

/**
 * Format dual currency display (Primary & Secondary)
 */
function formatDualCurrency(?float $primaryAmount, string $primaryCurr = 'NGN', ?float $secondaryAmount = null, string $secondaryCurr = 'USD'): string {
    if ($primaryAmount === null && $secondaryAmount === null) return '—';
    
    $out = formatMoney($primaryAmount, $primaryCurr);
    if ($secondaryAmount !== null && $secondaryAmount > 0) {
        $out .= ' <span style="color: var(--text-secondary); font-size: 0.85em;">(' . formatMoney($secondaryAmount, $secondaryCurr) . ')</span>';
    }
    return $out;
}

/**
 * Format date nicely
 */
function formatDate(?string $date, string $format = 'M d, Y'): string {
    if (empty($date) || $date === '0000-00-00') return '—';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

/**
 * Human-readable time elapsed (e.g. 2 hours ago)
 */
function timeAgo(?string $datetime): string {
    if (empty($datetime)) return '—';
    $ts = strtotime($datetime);
    if (!$ts) return '—';
    $diff = time() - $ts;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d, Y', $ts);
}

/**
 * User avatar URL resolver with fallback to initials/placeholder
 */
function getUserAvatarUrl(?array $user = null): string {
    if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/../uploads/profiles/' . $user['profile_picture'])) {
        return BASE_URL . '/uploads/profiles/' . $user['profile_picture'];
    }
    return BASE_URL . '/assets/images/avatar_placeholder.svg';
}

/**
 * Generate CSRF Token for form submissions
 */
function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token from POST request or header
 */
function verifyCsrfToken(?string $token = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) return false;
    
    $tokenToVerify = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return hash_equals($sessionToken, $tokenToVerify);
}

/**
 * HTML Badge for Asset Lifecycle Statuses
 */
function getBadgeForStatus(string $status): string {
    $normalized = strtolower(trim($status));
    $classes = [
        'available'   => 'badge-success',
        'assigned'    => 'badge-info',
        'in_repair'   => 'badge-warning',
        'damaged'     => 'badge-danger',
        'lost'        => 'badge-danger',
        'disposed'    => 'badge-secondary',
        'registered'  => 'badge-primary',
        'active'      => 'badge-success',
        'cleared'     => 'badge-success',
        'pending'     => 'badge-warning',
        'not_cleared' => 'badge-danger'
    ];
    
    $cls = $classes[$normalized] ?? 'badge-secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

/**
 * HTML Badge for Asset Condition Statuses
 */
function getBadgeForCondition(string $condition): string {
    $normalized = strtolower(trim($condition));
    $classes = [
        'new'     => 'badge-primary',
        'good'    => 'badge-success',
        'fair'    => 'badge-warning',
        'damaged' => 'badge-danger'
    ];
    
    $cls = $classes[$normalized] ?? 'badge-secondary';
    $label = ucfirst($condition);
    return "<span class=\"badge {$cls}\">{$label}</span>";
}

/**
 * JSON Response Helper for AJAX/API
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Safe HTTP Redirect
 */
function redirect(string $path): void {
    if (strpos($path, 'http') !== 0) {
        $path = BASE_URL . '/' . ltrim($path, '/');
    }
    header("Location: " . $path);
    exit;
}

/**
 * Set Session Flash Notification
 */
function flashMessage(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

/**
 * Get and Clear Flash Notification
 */
function getFlashMessage(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}
