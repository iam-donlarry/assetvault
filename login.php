<?php
declare(strict_types=1);

// login.php - AssetVault Root Authentication Page
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

startSession();

// If already logged in, redirect directly to dashboard
if (isLoggedIn()) {
    header("Location: " . BASE_URL . "/dashboard");
    exit;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session. Please refresh and try again.';
    } else {
        $identifier = sanitize($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            $error = 'Please enter your username/email and password.';
        } else {
            $result = login($identifier, $password);
            if ($result === true) {
                header("Location: " . BASE_URL . "/dashboard");
                exit;
            } else {
                $error = is_string($result) ? $result : 'Invalid credentials provided.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= APP_NAME ?></title>
    <link rel="icon" type="image/webp" href="<?= BASE_URL ?>/assets/images/bbsllogo.webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/lucide@0.344.0/dist/umd/lucide.min.js"></script>
    <style>
        :root {
            --primary-color: #12b1b0;
            --primary-hover: #0e8c8b;
            --primary-light: #e0f2f1;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --body-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            --card-bg: #ffffff;
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--body-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .login-card {
            background: var(--card-bg);
            border-radius: 1.25rem;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
            max-width: 960px;
            width: 100%;
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
        }

        .login-main {
            padding: 3.25rem 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 2.25rem;
            text-decoration: none;
        }

        .login-logo img {
            height: 40px;
            width: auto;
        }

        .login-logo-text {
            font-weight: 800;
            letter-spacing: -0.04em;
            font-size: 1.85rem;
            color: var(--primary-color);
            line-height: 1;
        }

        .login-title {
            font-weight: 700;
            font-size: 1.75rem;
            letter-spacing: -0.03em;
            margin-bottom: 0.35rem;
            color: var(--text-primary);
        }

        .login-subtitle {
            font-size: 0.925rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            font-weight: 400;
        }

        .error-alert {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            color: #991b1b;
            padding: 0.85rem 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .form-group {
            margin-bottom: 1.35rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.4rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper svg {
            position: absolute;
            left: 1rem;
            width: 18px;
            height: 18px;
            color: var(--text-secondary);
            pointer-events: none;
        }

        .login-input {
            width: 100%;
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            padding: 0.8rem 1rem 0.8rem 2.75rem;
            font-size: 0.9rem;
            background: #ffffff;
            color: var(--text-primary);
            font-family: inherit;
            transition: var(--transition);
        }

        .login-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(18, 177, 176, 0.15);
        }

        .btn-submit {
            width: 100%;
            padding: 0.85rem 1.5rem;
            background: var(--primary-color);
            color: #ffffff;
            border: none;
            border-radius: 0.75rem;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            box-shadow: 0 4px 14px rgba(18, 177, 176, 0.35);
            transform: translateY(-1px);
        }

        /* Right Side Branded Feature Banner */
        .login-aside {
            background: linear-gradient(135deg, #12b1b0 0%, #0a6968 100%);
            padding: 3rem 2.5rem;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .login-aside::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
        }

        .aside-header h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .aside-header p {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.6;
        }

        .feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin: 2rem 0;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .feature-icon {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 0.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .feature-text strong {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .feature-text span {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .aside-footer {
            font-size: 0.775rem;
            color: rgba(255, 255, 255, 0.75);
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            padding-top: 1rem;
        }

        @media (max-width: 840px) {
            .login-card {
                grid-template-columns: 1fr;
            }
            .login-aside {
                display: none;
            }
            .login-main {
                padding: 2.25rem 1.75rem;
            }
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-main">
        <a href="<?= BASE_URL ?>" class="login-logo">
            <img src="<?= BASE_URL ?>/assets/images/bbsllogo.webp" alt="<?= COMPANY_NAME ?> Logo">
            <span class="login-logo-text"><?= APP_NAME ?></span>
        </a>

        <h1 class="login-title">Welcome back</h1>
        <p class="login-subtitle">Sign in to your corporate asset management portal</p>

        <?php if (!empty($error)): ?>
            <div class="error-alert">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0;"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/login">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="identifier">Username or Corporate Email</label>
                <div class="input-wrapper">
                    <i data-lucide="mail"></i>
                    <input type="text" id="identifier" name="identifier" class="login-input" 
                           placeholder="admin or eazubike@bayebusinesssolutions.com" 
                           value="<?= e($identifier) ?>" required autofocus autocomplete="username">
                </div>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                    <label class="form-label" for="password" style="margin-bottom: 0;">Password</label>
                </div>
                <div class="input-wrapper">
                    <i data-lucide="lock"></i>
                    <input type="password" id="password" name="password" class="login-input" 
                           placeholder="••••••••••••" required autocomplete="current-password">
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <span>Sign In to AssetVault</span>
                <i data-lucide="arrow-right" style="width: 18px; height: 18px;"></i>
            </button>
        </form>

        <div style="margin-top: 2rem; text-align: center; font-size: 0.825rem; color: var(--text-secondary);">
            Default Administrator: <strong>admin</strong> | <strong>Admin@123456</strong>
        </div>
    </div>

    <div class="login-aside">
        <div class="aside-header">
            <h3>Enterprise Staff Asset Governance</h3>
            <p>Unified custody tracking, automated hardware provisioning, and exit clearances for Baye Business Solutions.</p>
        </div>

        <ul class="feature-list">
            <li class="feature-item">
                <div class="feature-icon">
                    <i data-lucide="laptop" style="width: 18px; height: 18px;"></i>
                </div>
                <div class="feature-text">
                    <strong>Dual-Tracking Inventory</strong>
                    <span>Serialized capital assets alongside bulk consumables.</span>
                </div>
            </li>
            <li class="feature-item">
                <div class="feature-icon">
                    <i data-lucide="shield-check" style="width: 18px; height: 18px;"></i>
                </div>
                <div class="feature-text">
                    <strong>Chain of Custody & Audit</strong>
                    <span>Immutable records of checkouts, returns & condition inspections.</span>
                </div>
            </li>
            <li class="feature-item">
                <div class="feature-icon">
                    <i data-lucide="coins" style="width: 18px; height: 18px;"></i>
                </div>
                <div class="feature-text">
                    <strong>Dual-Currency Financials</strong>
                    <span>Multi-currency procurement and valuation in NGN & USD.</span>
                </div>
            </li>
        </ul>

        <div class="aside-footer">
            &copy; <?= date('Y') ?> <?= COMPANY_NAME ?>. All rights reserved.
        </div>
    </div>
</div>

<script>
    if (window.lucide) {
        lucide.createIcons();
    }
</script>
</body>
</html>
