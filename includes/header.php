<?php
// includes/header.php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

startSession();
requireLogin();

$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? e($page_title) . ' — ' . APP_NAME : APP_NAME . ' — Staff Asset Management' ?></title>
    <link rel="icon" type="image/webp" href="<?= BASE_URL ?>/assets/images/bbsllogo.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.344.0/dist/umd/lucide.min.js"></script>
    <script>
        // Global App State
        window.BASE_URL = <?= json_encode(BASE_URL) ?>;
        window.CURRENT_ROLE = <?= json_encode($_SESSION['role_slug'] ?? 'staff') ?>;
        window.CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;

        // Restore Sidebar Mini state before render to prevent flicker
        if (localStorage.getItem('sidebar_mini') === '1' && window.innerWidth > 768) {
            document.documentElement.classList.add('sidebar-mini-active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (localStorage.getItem('sidebar_mini') === '1' && window.innerWidth > 768) {
                document.body.classList.add('sidebar-mini');
            }

            // Initialize Notyf for Toast notifications
            window.notyf = new Notyf({
                duration: 3500,
                position: { x: 'right', y: 'top' },
                types: [
                    { type: 'success', background: '#10b981', icon: false },
                    { type: 'error', background: '#ef4444', icon: false },
                    { type: 'warning', background: '#f59e0b', icon: false }
                ]
            });

            // Flash Message Trigger
            <?php if (!empty($flash)): ?>
                const flashType = <?= json_encode($flash['type'] ?? 'info') ?>;
                const flashMsg = <?= json_encode($flash['message'] ?? '') ?>;
                if (flashType === 'success') {
                    window.notyf.success(flashMsg);
                } else if (flashType === 'error') {
                    window.notyf.error(flashMsg);
                } else {
                    window.notyf.open({ type: 'warning', message: flashMsg });
                }
            <?php endif; ?>

            // Sidebar Toggle Logic (Desktop Mini & Mobile Drawer)
            const sidebarToggle = document.getElementById('sidebarToggle');
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const body = document.body;
                    const isMobile = window.innerWidth <= 768;
                    
                    if (isMobile) {
                        body.classList.toggle('sidebar-open');
                        body.classList.remove('sidebar-mini');
                    } else {
                        body.classList.toggle('sidebar-mini');
                        body.classList.remove('sidebar-open');
                        localStorage.setItem('sidebar_mini', body.classList.contains('sidebar-mini') ? '1' : '0');
                    }
                });
            }

            // Close mobile sidebar when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768 && document.body.classList.contains('sidebar-open')) {
                    const sidebar = document.querySelector('.sidebar');
                    const toggle = document.getElementById('sidebarToggle');
                    if (sidebar && !sidebar.contains(e.target) && toggle && !toggle.contains(e.target)) {
                        document.body.classList.remove('sidebar-open');
                    }
                }
            });

            // Action Dropdown toggles
            document.addEventListener('click', function(e) {
                const toggle = e.target.closest('.action-dropdown-toggle');
                const openDropdowns = document.querySelectorAll('.action-dropdown.open');
                
                if (toggle) {
                    e.preventDefault();
                    e.stopPropagation();
                    const parent = toggle.closest('.action-dropdown');
                    openDropdowns.forEach(d => {
                        if (d !== parent) d.classList.remove('open');
                    });
                    parent.classList.toggle('open');
                } else {
                    openDropdowns.forEach(d => d.classList.remove('open'));
                }
            });

            // Initialize Lucide Icons immediately
            if (window.lucide) {
                lucide.createIcons();
            }
        });
    </script>
</head>
<body class="<?= (isset($_COOKIE['sidebar_mini']) && $_COOKIE['sidebar_mini'] === '1') ? 'sidebar-mini' : '' ?>">

    <?php include __DIR__ . '/topbar.php'; ?>

    <div class="app-layout-container" style="display: flex; min-height: calc(100vh - 60px); margin-top: 60px;">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content" style="flex: 1; margin-left: 240px; padding: 2rem; min-width: 0; transition: margin-left 0.25s cubic-bezier(0.4, 0, 0.2, 1);">
            <div class="page-content">
