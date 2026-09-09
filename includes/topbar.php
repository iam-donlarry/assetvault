<?php
// includes/topbar.php
$alert_count = 0;
if (isset($pdo) && isLoggedIn()) {
    try {
        // Query pending offboardings + overdue maintenance
        $sql = "SELECT 
            (SELECT COUNT(*) FROM offboarding_requests WHERE clearance_status = 'pending') +
            (SELECT COUNT(*) FROM assets WHERE next_maintenance_date <= CURDATE() AND lifecycle_status != 'disposed') AS total_alerts";
        $alert_count = (int)$pdo->query($sql)->fetchColumn();
    } catch (Exception $e) {}
}
?>
<header class="topbar">
    <div class="topbar-left">
        <button id="sidebarToggle" class="btn-icon-toggle" aria-label="Toggle Sidebar Navigation">
            <i data-lucide="menu" style="width: 20px; height: 20px;"></i>
        </button>
        <a href="<?= BASE_URL ?>/dashboard" class="logo-wrapper" style="text-decoration: none;">
            <img src="<?= BASE_URL ?>/assets/images/bbsllogo.webp" alt="<?= COMPANY_NAME ?> Logo" class="logo-img">
            <span class="logo-text"><?= APP_NAME ?></span>
        </a>
    </div>
    
    <div class="topbar-actions">
        <a href="<?= BASE_URL ?>/maintenance" class="notification-bell" id="notifBell" title="System Alerts & Maintenance">
            <i data-lucide="bell" style="width: 20px; height: 20px;"></i>
            <span class="notification-badge" id="notifBadge" style="display: <?= $alert_count > 0 ? 'inline-block' : 'none' ?>;"><?= $alert_count ?></span>
        </a>
        
        <div class="user-menu" title="User Profile">
            <?php 
            $topbarAvatar = getUserAvatarUrl([
                'profile_picture' => $_SESSION['profile_picture'] ?? ''
            ]);
            ?>
            <img src="<?= htmlspecialchars($topbarAvatar) ?>" alt="Avatar" class="user-avatar" style="width: 34px; height: 34px; border-radius: 50%; object-fit: cover;">

            <div class="user-info">
                <span class="user-name">
                    <?= htmlspecialchars($_SESSION['user_name'] ?? 'Administrator') ?>
                </span>
                <span class="user-role"><?= htmlspecialchars($_SESSION['role_name'] ?? 'Super Admin') ?></span>
            </div>
        </div>
    </div>
</header>
