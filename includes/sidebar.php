<?php
// includes/sidebar.php
$user_id = $_SESSION['user_id'] ?? 0;
$active_page = $active_page ?? 'dashboard';

// Pending counts for sidebar badges
$pendingOffboardingCount = 0;
$overdueMaintenanceCount = 0;
if (isset($pdo)) {
    try {
        $pendingOffboardingCount = (int)$pdo->query("SELECT COUNT(*) FROM offboarding_requests WHERE clearance_status = 'pending'")->fetchColumn();
        $overdueMaintenanceCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE next_maintenance_date <= CURDATE() AND lifecycle_status != 'disposed'")->fetchColumn();
    } catch (Exception $e) {}
}
?>
<aside class="sidebar">
    <nav class="sidebar-nav">
        <!-- 1. MAIN -->
        <div class="sidebar-section-header">Main</div>
        <a href="<?= BASE_URL ?>/dashboard" class="nav-link <?= $active_page === 'dashboard' ? 'active' : '' ?>">
            <i data-lucide="layout-dashboard" class="nav-icon"></i> 
            <span class="nav-text">Dashboard</span>
        </a>

        <!-- 2. ASSET INVENTORY -->
        <div class="sidebar-section-header">Asset & Inventory</div>
        <?php $assets_group_active = in_array($active_page, ['catalog', 'assets', 'assets_create', 'inventory', 'categories', 'suppliers', 'locations']); ?>
        <details class="nav-group" <?= $assets_group_active ? 'open' : '' ?>>
            <summary class="nav-group-summary">
                <i data-lucide="boxes" class="nav-icon"></i>
                <span class="nav-text">Catalog & Stock</span>
                <i data-lucide="chevron-right" class="caret-icon"></i>
            </summary>
            <div class="sub-menu">
                <?php if (hasPermission('catalog.view') || hasPermission('assets.view')): ?>
                <a href="<?= BASE_URL ?>/catalog" class="sub-nav-link <?= $active_page === 'catalog' ? 'active' : '' ?>">
                    <span class="nav-text">Product Catalog</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('assets.view')): ?>
                <a href="<?= BASE_URL ?>/assets" class="sub-nav-link <?= $active_page === 'assets' ? 'active' : '' ?>">
                    <span class="nav-text">Serialized Assets</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('inventory.view') || hasPermission('assets.view')): ?>
                <a href="<?= BASE_URL ?>/inventory" class="sub-nav-link <?= $active_page === 'inventory' ? 'active' : '' ?>">
                    <span class="nav-text">Bulk Inventory</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('assets.create')): ?>
                <a href="<?= BASE_URL ?>/assets/create" class="sub-nav-link <?= $active_page === 'assets_create' ? 'active' : '' ?>">
                    <span class="nav-text">New Serialized Asset</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('settings.manage')): ?>
                <a href="<?= BASE_URL ?>/categories" class="sub-nav-link <?= $active_page === 'categories' ? 'active' : '' ?>">
                    <span class="nav-text">Categories</span>
                </a>
                <a href="<?= BASE_URL ?>/suppliers" class="sub-nav-link <?= $active_page === 'suppliers' ? 'active' : '' ?>">
                    <span class="nav-text">Suppliers</span>
                </a>
                <a href="<?= BASE_URL ?>/locations" class="sub-nav-link <?= $active_page === 'locations' ? 'active' : '' ?>">
                    <span class="nav-text">Locations</span>
                </a>
                <?php endif; ?>
            </div>
        </details>

        <!-- 3. CUSTODY & LIFECYCLE -->
        <div class="sidebar-section-header">Custody & Lifecycle</div>
        <?php $custody_active = in_array($active_page, ['assignments', 'assignments_checkout', 'movements', 'maintenance']); ?>
        <details class="nav-group" <?= $custody_active ? 'open' : '' ?>>
            <summary class="nav-group-summary">
                <i data-lucide="arrow-left-right" class="nav-icon"></i>
                <span class="nav-text">Custody</span>
                <i data-lucide="chevron-right" class="caret-icon"></i>
            </summary>
            <div class="sub-menu">
                <?php if (hasPermission('assets.assign') || hasPermission('assets.view')): ?>
                <a href="<?= BASE_URL ?>/assignments" class="sub-nav-link <?= $active_page === 'assignments' ? 'active' : '' ?>">
                    <span class="nav-text">Assignments</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('assets.assign')): ?>
                <a href="<?= BASE_URL ?>/assignments/checkout" class="sub-nav-link <?= $active_page === 'assignments_checkout' ? 'active' : '' ?>">
                    <span class="nav-text">Issue / Check Out</span>
                </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/movements" class="sub-nav-link <?= $active_page === 'movements' ? 'active' : '' ?>">
                    <span class="nav-text">Movements</span>
                </a>

                <?php if (hasPermission('maintenance.view')): ?>
                <a href="<?= BASE_URL ?>/maintenance" class="sub-nav-link <?= $active_page === 'maintenance' ? 'active' : '' ?>">
                    <span class="nav-text">Maintenance</span>
                    <?php if ($overdueMaintenanceCount > 0): ?>
                        <span class="nav-badge" style="background: var(--warning);"><?= $overdueMaintenanceCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </details>

        <!-- 4. STAFF & PROVISIONING -->
        <div class="sidebar-section-header">Staff & Provisioning</div>
        <?php $staff_active = in_array($active_page, ['staff', 'onboarding', 'offboarding']); ?>
        <details class="nav-group" <?= $staff_active ? 'open' : '' ?>>
            <summary class="nav-group-summary">
                <i data-lucide="users" class="nav-icon"></i>
                <span class="nav-text">Employees</span>
                <i data-lucide="chevron-right" class="caret-icon"></i>
            </summary>
            <div class="sub-menu">
                <?php if (hasPermission('staff.view')): ?>
                <a href="<?= BASE_URL ?>/staff" class="sub-nav-link <?= $active_page === 'staff' ? 'active' : '' ?>">
                    <span class="nav-text">Staff Directory</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('onboarding.view')): ?>
                <a href="<?= BASE_URL ?>/onboarding" class="sub-nav-link <?= $active_page === 'onboarding' ? 'active' : '' ?>">
                    <span class="nav-text">Onboarding</span>
                </a>
                <?php endif; ?>

                <?php if (hasPermission('offboarding.view')): ?>
                <a href="<?= BASE_URL ?>/offboarding" class="sub-nav-link <?= $active_page === 'offboarding' ? 'active' : '' ?>">
                    <span class="nav-text">Exit Clearance</span>
                    <?php if ($pendingOffboardingCount > 0): ?>
                        <span class="nav-badge"><?= $pendingOffboardingCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </div>
        </details>

        <!-- 5. REPORTS -->
        <div class="sidebar-section-header">Analytics</div>
        <?php if (hasPermission('reports.view')): ?>
        <a href="<?= BASE_URL ?>/reports" class="nav-link <?= $active_page === 'reports' ? 'active' : '' ?>">
            <i data-lucide="bar-chart-3" class="nav-icon"></i> 
            <span class="nav-text">Reports</span>
        </a>
        <?php endif; ?>

        <!-- 6. ADMINISTRATION -->
        <?php if (hasPermission('settings.manage') || hasPermission('users.manage') || hasPermission('audit.view')): ?>
        <div class="sidebar-section-header">Administration</div>
        
        <?php if (hasPermission('users.manage')): ?>
        <a href="<?= BASE_URL ?>/users" class="nav-link <?= $active_page === 'users' ? 'active' : '' ?>">
            <i data-lucide="user-cog" class="nav-icon"></i> 
            <span class="nav-text">Users & Access</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('settings.manage')): ?>
        <a href="<?= BASE_URL ?>/settings" class="nav-link <?= $active_page === 'settings' ? 'active' : '' ?>">
            <i data-lucide="settings" class="nav-icon"></i> 
            <span class="nav-text">Settings</span>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('audit.view')): ?>
        <a href="<?= BASE_URL ?>/audit" class="nav-link <?= $active_page === 'audit' ? 'active' : '' ?>">
            <i data-lucide="history" class="nav-icon"></i> 
            <span class="nav-text">Audit Trail</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

    </nav>

    <!-- SIDEBAR FOOTER (LOGOUT) -->
    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/logout" class="btn-sidebar-logout">
            <i data-lucide="log-out" style="margin-right: 0.5rem; width: 16px; height: 16px;"></i> 
            <span class="nav-text">Sign Out</span>
        </a>
    </div>
</aside>
