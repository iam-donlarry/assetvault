<?php
// modules/settings/index.php - General System & Financial Configuration
declare(strict_types=1);

$active_page = 'settings';
$page_title = 'System Settings';

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();
requirePermission('settings.manage');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    if (!verifyCsrfToken()) {
        $error = 'Invalid security session.';
    } else {
        $settings = $_POST['settings'] ?? [];
        try {
            $pdo->beginTransaction();
            $upd = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            foreach ($settings as $key => $val) {
                $upd->execute([trim((string)$val), $key]);
            }
            logAudit('settings.update', 'system_settings', 1, null, $settings);
            $pdo->commit();
            flashMessage('success', 'System configuration updated successfully.');
            redirect('settings');
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Fetch all settings
$settingsList = $pdo->query("SELECT * FROM system_settings ORDER BY setting_group ASC, id ASC")->fetchAll();
$settingsMap = [];
foreach ($settingsList as $s) {
    $settingsMap[$s['setting_key']] = $s['setting_value'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1 style="font-size: 1.6rem; margin-bottom: 0.2rem;">System Configuration</h1>
        <p style="color: var(--text-secondary); margin-bottom: 0; font-size: 0.9rem;">
            Configure organizational identity, dual-currency defaults, and automated alert thresholds.
        </p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div style="background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 0.85rem 1.25rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<div style="max-width: 800px;">
    <form method="POST" action="<?= BASE_URL ?>/settings" class="card" style="padding: 2rem;">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">

        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary-color);">
            1. Organization & General
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Application Name</label>
                <input type="text" name="settings[app_name]" class="form-control" value="<?= e($settingsMap['app_name'] ?? 'AssetVault') ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Company Entity Name</label>
                <input type="text" name="settings[company_name]" class="form-control" value="<?= e($settingsMap['company_name'] ?? 'Baye Business Solutions') ?>" required>
            </div>
        </div>

        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary-color);">
            2. Dual-Currency Settings
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label">Base Primary Currency</label>
                <input type="text" name="settings[base_currency]" class="form-control" value="<?= e($settingsMap['base_currency'] ?? 'NGN') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Primary Currency Symbol</label>
                <input type="text" name="settings[base_currency_symbol]" class="form-control" value="<?= e($settingsMap['base_currency_symbol'] ?? '₦') ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem;">
            <div class="form-group">
                <label class="form-label">Secondary Currency</label>
                <input type="text" name="settings[secondary_currency]" class="form-control" value="<?= e($settingsMap['secondary_currency'] ?? 'USD') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Secondary Currency Symbol</label>
                <input type="text" name="settings[secondary_currency_symbol]" class="form-control" value="<?= e($settingsMap['secondary_currency_symbol'] ?? '$') ?>" required>
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label class="form-label">Default Exchange Rate (1 USD = NGN)</label>
            <input type="number" step="0.0001" name="settings[default_exchange_rate]" class="form-control" value="<?= e($settingsMap['default_exchange_rate'] ?? '1500.0000') ?>" required>
        </div>

        <h3 style="font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem; margin-bottom: 1.25rem; color: var(--primary-color);">
            3. Automated Alert Intervals
        </h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 2rem;">
            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Warranty Expiry Alert (Days in advance)</label>
                <input type="number" name="settings[warranty_alert_days]" class="form-control" value="<?= e($settingsMap['warranty_alert_days'] ?? '30') ?>" required>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">Maintenance Due Alert (Days in advance)</label>
                <input type="number" name="settings[maintenance_alert_days]" class="form-control" value="<?= e($settingsMap['maintenance_alert_days'] ?? '14') ?>" required>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">
                <i data-lucide="check"></i> Save Configuration
            </button>
        </div>
    </form>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
