<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

/* ═══════════════════════════════════════════════════
   POST handlers
   ═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flash('danger', 'درخواست نامعتبر.');
        redirect('/settings.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $tabRedirect = match ($action) {
        'save_wireguard' => 'wg',
        'save_app' => 'app',
        'save_admin' => 'admin',
        'save_api', 'regen_token' => 'api',
        'save_backup', 'save_backup_path', 'run_backup', 'restore_backup', 'delete_backup' => 'backup',
        'truncate_logs', 'truncate_all' => 'db',
        default => '',
    };

    try {
        /* ── WireGuard settings ── */
        if ($action === 'save_wireguard') {
            savePanelSettings([
                'wireguard' => [
                    'endpoint'            => trim((string) ($_POST['endpoint']            ?? '')),
                    'dns'                 => trim((string) ($_POST['dns']                 ?? '')),
                    'allowed_ips'         => trim((string) ($_POST['allowed_ips']         ?? '')),
                    'persistent_keepalive'=> max(1, (int) ($_POST['persistent_keepalive'] ?? 25)),
                    'online_timeout'      => max(60, (int) ($_POST['online_timeout']      ?? 185)),
                    'server_public_key'   => trim((string) ($_POST['server_public_key']   ?? '')),
                ],
            ]);
            flash('success', 'تنظیمات WireGuard ذخیره شد.');
            $tabRedirect = 'wg';
        }

        /* ── App settings ── */
        elseif ($action === 'save_app') {
            $baseUrl = rtrim(trim((string) ($_POST['subscribe_base_url'] ?? '')), '/');
            savePanelSettings([
                'app' => [
                    'name'               => trim((string) ($_POST['app_name'] ?? 'WireGuard Panel')),
                    'subscribe_base_url' => $baseUrl,
                    'timezone'           => trim((string) ($_POST['timezone'] ?? 'Asia/Tehran')),
                ],
            ]);
            flash('success', 'تنظیمات پنل ذخیره شد.');
            $tabRedirect = 'app';
        }

        /* ── Admin credentials ── */
        elseif ($action === 'save_admin') {
            $newUsername   = trim((string) ($_POST['new_username']    ?? ''));
            $currentPass   = (string) ($_POST['current_password']     ?? '');
            $newPass       = (string) ($_POST['new_password']         ?? '');
            $confirmPass   = (string) ($_POST['confirm_password']     ?? '');
            $loginPathRaw  = trim((string) ($_POST['login_path']       ?? ''));

            if (!password_verify($currentPass, $config['admin']['password_hash'])) {
                throw new RuntimeException('رمز عبور فعلی اشتباه است.');
            }

            $changes = [];
            $loginPathChanged = false;

            if ($newUsername !== '' && $newUsername !== $config['admin']['username']) {
                $changes['username'] = $newUsername;
            }

            if ($newPass !== '') {
                if ($newPass !== $confirmPass) {
                    throw new RuntimeException('رمز عبور جدید و تکرار آن یکسان نیستند.');
                }
                if (strlen($newPass) < 8) {
                    throw new RuntimeException('رمز عبور باید حداقل ۸ کاراکتر باشد.');
                }
                $changes['password_hash'] = password_hash($newPass, PASSWORD_BCRYPT);
            }

            $normalizedLoginPath = WgPanel\AdminPath::normalizeSlug($loginPathRaw);
            $currentLoginPath = WgPanel\AdminPath::slug($config);
            if ($normalizedLoginPath !== $currentLoginPath) {
                $changes['login_path'] = $normalizedLoginPath;
                $loginPathChanged = true;
            }

            if (!empty($changes)) {
                savePanelSettings(['admin' => $changes]);

                $msg = 'اطلاعات مدیر ذخیره شد.';
                if ($loginPathChanged) {
                    $msg .= ' مسیر ورود: ' . WgPanel\AdminPath::url($config);
                }
                flash('success', $msg);
            } else {
                flash('success', 'تغییری اعمال نشد.');
            }
            $tabRedirect = 'admin';
        }

        /* ── API settings ── */
        elseif ($action === 'save_api') {
            $enabled = isset($_POST['api_enabled']);
            savePanelSettings(['api' => ['enabled' => $enabled]]);
            flash('success', 'تنظیمات API ذخیره شد.');
            $tabRedirect = 'api';
        }

        elseif ($action === 'regen_token') {
            $token = bin2hex(random_bytes(32));
            savePanelSettings(['api' => ['token' => $token]]);
            flash('success', 'توکن API جدید ساخته شد.');
            $tabRedirect = 'api';
        }

        /* ── Backup settings ── */
        elseif ($action === 'save_backup') {
            $interval = (int) ($_POST['interval_hours'] ?? 24);
            $allowedIntervals = WgPanel\BackupManager::intervalOptions();
            $includeWg = isset($_POST['include_wg_conf']);
            $includeDb = isset($_POST['include_database']);

            if (!in_array($interval, $allowedIntervals, true)) {
                throw new RuntimeException('بازه زمانی بک‌آپ نامعتبر است.');
            }

            if (isset($_POST['backup_enabled']) && !$includeWg && !$includeDb) {
                throw new RuntimeException('حداقل یکی از wg0.conf یا دیتابیس باید انتخاب شود.');
            }

            savePanelSettings([
                'backup' => [
                    'enabled' => isset($_POST['backup_enabled']),
                    'interval_hours' => $interval,
                    'include_wg_conf' => $includeWg,
                    'include_database' => $includeDb,
                    'retention_count' => max(1, min(100, (int) ($_POST['retention_count'] ?? 14))),
                ],
            ]);
            flash('success', 'تنظیمات بک‌آپ ذخیره شد.');
            $tabRedirect = 'backup';
        }

        elseif ($action === 'save_backup_path') {
            $raw = trim((string) ($_POST['backup_dir'] ?? ''));
            $resolved = WgPanel\BackupManager::resolveStorageDir(
                ['backup' => ['backup_dir' => $raw]],
                dirname(__DIR__)
            );

            if (!is_dir($resolved) && !@mkdir($resolved, 0750, true) && !is_dir($resolved)) {
                throw new RuntimeException('پوشه بک‌آپ قابل ساخت نیست: ' . $resolved);
            }

            if (!is_writable($resolved)) {
                throw new RuntimeException('پوشه بک‌آپ قابل نوشتن نیست: ' . $resolved);
            }

            savePanelSettings(['backup' => ['backup_dir' => $raw]]);
            flash('success', 'مسیر بک‌آپ ذخیره شد: ' . $resolved);
            $tabRedirect = 'backup';
        }

        elseif ($action === 'run_backup') {
            $includeWg = isset($_POST['include_wg_conf']);
            $includeDb = isset($_POST['include_database']);

            if (!$includeWg && !$includeDb) {
                throw new RuntimeException('حداقل یکی از wg0.conf یا دیتابیس باید انتخاب شود.');
            }

            $manager = backupManager();
            $result = $manager->create($config, $includeWg, $includeDb);
            $manager->prune(max(1, (int) ($config['backup']['retention_count'] ?? 14)));

            flash(
                'success',
                'بک‌آپ ساخته شد: ' . $result['filename']
                . ' (' . WgPanel\BackupManager::formatBytes($result['size']) . ')'
            );
            $tabRedirect = 'backup';
        }

        elseif ($action === 'restore_backup') {
            $pin = (string) ($_POST['restore_pin'] ?? '');
            if ($pin !== '1565') {
                throw new RuntimeException('رمز تأیید اشتباه است.');
            }

            $filename = basename((string) ($_POST['backup_file'] ?? ''));
            $result = backupManager()->restore($config, $filename);
            $parts = $result['restored'] === [] ? '—' : implode(' + ', $result['restored']);
            flash('success', 'ریستور شد (' . $parts . '): ' . $result['filename']);
            $tabRedirect = 'backup';
        }

        elseif ($action === 'delete_backup') {
            $filename = basename((string) ($_POST['backup_file'] ?? ''));
            backupManager()->delete($filename);
            flash('success', 'بک‌آپ حذف شد.');
            $tabRedirect = 'backup';
        }

        /* ── Database truncate ── */
        elseif ($action === 'truncate_logs') {
            $pin = (string) ($_POST['truncate_pin'] ?? '');
            if ($pin !== '1565') {
                throw new RuntimeException('رمز تأیید اشتباه است.');
            }
            $db->exec('DELETE FROM traffic_logs');
            flash('success', 'جدول traffic_logs پاک شد.');
            $tabRedirect = 'db';
        }

        elseif ($action === 'truncate_all') {
            $pin = (string) ($_POST['truncate_pin'] ?? '');
            if ($pin !== '1565') {
                throw new RuntimeException('رمز تأیید اشتباه است.');
            }
            // Remove peers from WireGuard first
            $accounts = $db->query('SELECT * FROM accounts')->fetchAll();
            foreach ($accounts as $acc) {
                try {
                    WgPanel\Shell::run(
                        '/usr/bin/wg set ' . escapeshellarg((string) ($config['wireguard']['interface'] ?? 'wg0'))
                        . ' peer ' . escapeshellarg((string) $acc['public_key']) . ' remove',
                        false,
                        true
                    );
                } catch (Throwable) {}
            }
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
            $db->exec('TRUNCATE TABLE traffic_logs');
            $db->exec('TRUNCATE TABLE accounts');
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            // Also clean wg0.conf peer sections
            $interface = (string) ($config['wireguard']['interface'] ?? 'wg0');
            $confPath  = (string) ($config['wireguard']['config_path'] ?? "/etc/wireguard/{$interface}.conf");
            if (is_writable($confPath)) {
                $lines   = file($confPath, FILE_IGNORE_NEW_LINES) ?: [];
                $cleaned = [];
                $inPeer  = false;
                foreach ($lines as $line) {
                    if (strtolower(trim($line)) === '[peer]') { $inPeer = true; continue; }
                    if ($inPeer && str_starts_with(trim($line), '[')) { $inPeer = false; }
                    if (!$inPeer) { $cleaned[] = $line; }
                }
                file_put_contents($confPath, implode(PHP_EOL, $cleaned) . PHP_EOL);
            }
            flash('success', 'تمام اکانت‌ها و لاگ‌ها پاک شدند و wg0.conf بازنویسی شد.');
            $tabRedirect = 'db';
        }

    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('/settings.php' . ($tabRedirect !== '' ? '?tab=' . rawurlencode($tabRedirect) : ''));
}

/* ═══════════════════════════════════════════════════
   Load config for display (file + DB overlay from bootstrap)
   ═══════════════════════════════════════════════════ */
$cfg = $config;
$wg  = $cfg['wireguard'] ?? [];
$app = $cfg['app']        ?? [];
$api = $cfg['api']        ?? [];
$adm = $cfg['admin']      ?? [];
$backup = $cfg['backup']  ?? [];
$adminLoginPath = WgPanel\AdminPath::url($cfg);
$backupManager = backupManager();
$backupList = $backupManager->listBackups();
$backupDir = $backupManager->directory();
$backupDirWritable = $backupManager->isWritable();
$backupDirCustom = trim((string) ($backup['backup_dir'] ?? ''));
$backupIntervals = [
    6 => 'هر ۶ ساعت',
    12 => 'هر ۱۲ ساعت',
    24 => 'روزانه (۲۴ ساعت)',
    48 => 'هر ۲ روز',
    168 => 'هفتگی',
];

$logCount     = (int) $db->query('SELECT COUNT(*) FROM traffic_logs')->fetchColumn();
$accountCount = (int) $db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();

$timezones = ['Asia/Tehran', 'UTC', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'Asia/Dubai', 'Asia/Istanbul'];

$pageTitle = 'تنظیمات';
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>تنظیمات</h1>
        <p class="muted">مدیریت تنظیمات پنل و WireGuard</p>
    </div>
    <div class="actions">
        <a href="/" class="btn btn-secondary">بازگشت</a>
    </div>
</div>

<!-- ── Tab nav ── -->
<div class="settings-tabs">
    <button class="stab active" onclick="showTab('backup')">💾 بک‌آپ</button>
    <button class="stab" onclick="showTab('wg')">🔒 WireGuard</button>
    <button class="stab" onclick="showTab('app')">⚙️ پنل</button>
    <button class="stab" onclick="showTab('admin')">👤 مدیر</button>
    <button class="stab" onclick="showTab('api')">🔑 API</button>
    <button class="stab stab-danger" onclick="showTab('db')">🗄️ دیتابیس</button>
</div>

<!-- ═══ Backup ═══ -->
<div id="tab-backup" class="stab-panel">
    <div class="card settings-card">
        <h2>بک‌آپ دستی</h2>
        <?php if (!$backupDirWritable): ?>
        <div class="alert alert-danger">
            پوشه بک‌آپ قابل نوشتن نیست. روی سرور اجرا کنید:
            <code>mkdir -p <?= e($backupDir) ?> && chown www-data:www-data <?= e($backupDir) ?> && chmod 750 <?= e($backupDir) ?></code>
        </div>
        <?php endif; ?>

        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_backup_path">
            <div class="settings-row">
                <label>مسیر ذخیره بک‌آپ</label>
                <input type="text" name="backup_dir" value="<?= e($backupDirCustom) ?>" placeholder="<?= e(WgPanel\BackupManager::defaultStorageDir(dirname(__DIR__))) ?>" dir="ltr">
                <span class="settings-hint">خالی = مسیر پیش‌فرض. مسیر مطلق لینوکسی، خارج از public. مسیر فعلی: <code dir="ltr"><?= e($backupDir) ?></code></span>
            </div>
            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">ذخیره مسیر</button>
            </div>
        </form>

        <div class="settings-sep"></div>

        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="run_backup">

            <div class="settings-grid-2">
                <div class="settings-row settings-toggle-row">
                    <label>
                        <input type="checkbox" name="include_wg_conf" value="1" checked>
                        wg0.conf
                    </label>
                </div>
                <div class="settings-row settings-toggle-row">
                    <label>
                        <input type="checkbox" name="include_database" value="1" checked>
                        دیتابیس (SQL)
                    </label>
                </div>
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary" <?= $backupDirWritable ? '' : 'disabled' ?>>📦 بک‌آپ الآن</button>
            </div>
        </form>

        <div class="settings-sep"></div>

        <h2>بک‌آپ‌های موجود</h2>
        <?php if ($backupList === []): ?>
            <p class="muted">هنوز بک‌آپی ثبت نشده است.</p>
        <?php else: ?>
            <div class="backup-list">
                <?php foreach ($backupList as $item): ?>
                <div class="backup-item">
                    <div class="backup-item-info">
                        <strong dir="ltr"><?= e($item['filename']) ?></strong>
                        <span class="muted">
                            <?= e(date('Y-m-d H:i', $item['created_at'])) ?>
                            · <?= e(WgPanel\BackupManager::formatBytes($item['size'])) ?>
                        </span>
                    </div>
                    <div class="backup-item-actions">
                        <a href="/backup-download.php?file=<?= e(urlencode($item['filename'])) ?>" class="btn btn-secondary btn-small">دانلود</a>
                        <form method="post" class="backup-delete-form" onsubmit="return confirm('این بک‌آپ روی دیتابیس و wg0.conf بازنویسی می‌شود. ادامه می‌دهید؟');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="restore_backup">
                            <input type="hidden" name="backup_file" value="<?= e($item['filename']) ?>">
                            <input type="text" name="restore_pin" placeholder="رمز تأیید" maxlength="10" autocomplete="off" class="truncate-pin-input" required>
                            <button type="submit" class="btn btn-primary btn-small">ریستور</button>
                        </form>
                        <form method="post" class="backup-delete-form" onsubmit="return confirm('این بک‌آپ حذف شود؟');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete_backup">
                            <input type="hidden" name="backup_file" value="<?= e($item['filename']) ?>">
                            <button type="submit" class="btn btn-danger btn-small">حذف</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="muted" style="margin-top:.75rem">ریستور برگشت‌ناپذیر است. رمز تأیید همان رمز بخش دیتابیس است.</p>
        <?php endif; ?>

        <div class="settings-sep"></div>

        <h2>بک‌آپ خودکار (اختیاری)</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_backup">

            <div class="settings-row settings-toggle-row">
                <label>
                    <input type="checkbox" name="backup_enabled" value="1" <?= !empty($backup['enabled']) ? 'checked' : '' ?>>
                    فعال‌سازی بک‌آپ خودکار
                </label>
            </div>

            <div class="settings-row">
                <label>بازه زمانی</label>
                <select name="interval_hours">
                    <?php foreach ($backupIntervals as $hours => $label): ?>
                        <option value="<?= (int) $hours ?>" <?= (int) ($backup['interval_hours'] ?? 24) === $hours ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="settings-hint">cron ساعتی بررسی می‌کند و در صورت رسیدن به بازه، بک‌آپ می‌گیرد.</span>
            </div>

            <div class="settings-grid-2">
                <div class="settings-row settings-toggle-row">
                    <label>
                        <input type="checkbox" name="include_wg_conf" value="1" <?= !isset($backup['include_wg_conf']) || !empty($backup['include_wg_conf']) ? 'checked' : '' ?>>
                        wg0.conf
                    </label>
                </div>
                <div class="settings-row settings-toggle-row">
                    <label>
                        <input type="checkbox" name="include_database" value="1" <?= !isset($backup['include_database']) || !empty($backup['include_database']) ? 'checked' : '' ?>>
                        دیتابیس (SQL)
                    </label>
                </div>
            </div>

            <div class="settings-row">
                <label>نگهداری آخرین بک‌آپ‌ها</label>
                <input type="number" name="retention_count" value="<?= (int) ($backup['retention_count'] ?? 14) ?>" min="1" max="100">
                <span class="settings-hint">بک‌آپ‌های قدیمی‌تر از این تعداد حذف می‌شوند.</span>
            </div>

            <?php if (!empty($backup['last_run_at'])): ?>
            <div class="settings-row">
                <label>آخرین بک‌آپ خودکار</label>
                <input type="text" value="<?= e(date('Y-m-d H:i:s', (int) $backup['last_run_at'])) ?>" readonly dir="ltr">
            </div>
            <?php endif; ?>

            <div class="settings-actions">
                <button type="submit" class="btn btn-secondary">ذخیره تنظیمات خودکار</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ WireGuard ═══ -->
<div id="tab-wg" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>تنظیمات WireGuard</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="save_wireguard">

            <div class="settings-row">
                <label>Endpoint (آدرس:پورت)</label>
                <input type="text" name="endpoint" value="<?= e((string) ($wg['endpoint'] ?? '')) ?>" placeholder="example.com:51820">
            </div>
            <div class="settings-row">
                <label>DNS</label>
                <input type="text" name="dns" value="<?= e((string) ($wg['dns'] ?? '')) ?>" placeholder="1.1.1.1, 8.8.8.8">
            </div>
            <div class="settings-row">
                <label>AllowedIPs</label>
                <input type="text" name="allowed_ips" value="<?= e((string) ($wg['allowed_ips'] ?? '')) ?>" placeholder="0.0.0.0/0, ::/0">
                <span class="settings-hint">برای split-tunnel فقط ips خاص بگذارید</span>
            </div>
            <div class="settings-grid-2">
                <div class="settings-row">
                    <label>Persistent Keepalive (ثانیه)</label>
                    <input type="number" name="persistent_keepalive" value="<?= (int) ($wg['persistent_keepalive'] ?? 25) ?>" min="1" max="300">
                </div>
                <div class="settings-row">
                    <label>Online Timeout (ثانیه)</label>
                    <input type="number" name="online_timeout" value="<?= (int) ($wg['online_timeout'] ?? 185) ?>" min="60" max="600">
                    <span class="settings-hint">حداقل باید ≥ keepalive + 15 باشد</span>
                </div>
            </div>
            <div class="settings-row">
                <label>Server Public Key</label>
                <input type="text" name="server_public_key" value="<?= e((string) ($wg['server_public_key'] ?? '')) ?>" placeholder="کلید عمومی سرور WireGuard" dir="ltr">
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات WireGuard</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ App ═══ -->
<div id="tab-app" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>تنظیمات پنل</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="save_app">

            <div class="settings-row">
                <label>نام پنل</label>
                <input type="text" name="app_name" value="<?= e((string) ($app['name'] ?? 'WireGuard Panel')) ?>">
            </div>
            <div class="settings-row">
                <label>آدرس پایه صفحه کاربری (subscribe_base_url)</label>
                <input type="url" name="subscribe_base_url" value="<?= e((string) ($app['subscribe_base_url'] ?? '')) ?>" placeholder="https://example.com" dir="ltr">
                <span class="settings-hint">آدرسی که کاربران برای باز کردن Web Link استفاده می‌کنند</span>
            </div>
            <div class="settings-row">
                <label>منطقه زمانی</label>
                <select name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= ($app['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات پنل</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ Admin ═══ -->
<div id="tab-admin" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>اطلاعات مدیر</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="save_admin">

            <div class="settings-row">
                <label>مسیر ورود ادمین (obfuscation)</label>
                <div class="settings-token-row">
                    <input type="text" name="login_path" id="admin-login-path"
                           value="<?= e((string) ($adm['login_path'] ?? '')) ?>"
                           placeholder="خالی = /login.php" dir="ltr" pattern="[a-zA-Z0-9_-]{0,48}">
                    <button type="button" class="btn btn-secondary btn-small" onclick="generateAdminPath()">تولید تصادفی</button>
                </div>
                <span class="settings-hint">
                    مثال: <code dir="ltr">sisi</code> → آدرس ورود:
                    <code dir="ltr"><?= e($adminLoginPath) ?></code>.
                    با مقدار غیرخالی، دسترسی مستقیم به <code>/login.php</code> و صفحات محافظت‌شده بدون ورود، 404 می‌دهند (بدون ریدایرکت به مسیر مخفی).
                    فقط با رفتن مستقیم به مسیر بالا صفحه ورود نمایش داده می‌شود.
                </span>
            </div>

            <div class="settings-row">
                <label>نام کاربری فعلی</label>
                <input type="text" name="new_username" value="<?= e((string) ($adm['username'] ?? '')) ?>">
            </div>
            <div class="settings-row">
                <label>رمز عبور فعلی <span class="settings-required">*</span></label>
                <input type="password" name="current_password" required placeholder="برای اعمال تغییر الزامی است">
            </div>
            <div class="settings-grid-2">
                <div class="settings-row">
                    <label>رمز عبور جدید</label>
                    <input type="password" name="new_password" placeholder="خالی = بدون تغییر">
                </div>
                <div class="settings-row">
                    <label>تکرار رمز جدید</label>
                    <input type="password" name="confirm_password" placeholder="تکرار رمز جدید">
                </div>
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">ذخیره اطلاعات مدیر</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ API ═══ -->
<div id="tab-api" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>تنظیمات API</h2>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="save_api">

            <div class="settings-row settings-toggle-row">
                <label>
                    <input type="checkbox" name="api_enabled" value="1" <?= !empty($api['enabled']) ? 'checked' : '' ?>>
                    فعال بودن API
                </label>
            </div>

            <div class="settings-row">
                <label>توکن API</label>
                <div class="settings-token-row">
                    <input type="text" value="<?= e((string) ($api['token'] ?? '')) ?>" readonly dir="ltr" id="api-token-display">
                    <button type="button" class="btn btn-secondary btn-small" onclick="copyApiToken()">کپی</button>
                </div>
            </div>

            <div class="settings-actions">
                <button type="submit" class="btn btn-primary">ذخیره تنظیمات API</button>
            </div>
        </form>

        <div class="settings-sep"></div>

        <form method="post" class="settings-form" onsubmit="return confirm('یک توکن جدید ساخته می‌شود و توکن قبلی دیگر کار نخواهد کرد. ادامه می‌دهید؟');">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="regen_token">
            <p class="muted" style="margin-bottom:.75rem">ساخت توکن جدید — توکن فعلی بلافاصله نامعتبر می‌شود.</p>
            <button type="submit" class="btn btn-secondary">🔄 ساخت توکن جدید</button>
        </form>
    </div>
</div>
<!-- ═══ Database ═══ -->
<div id="tab-db" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>مدیریت دیتابیس</h2>

        <div class="db-stat-row">
            <div class="db-stat">
                <span class="db-stat-num"><?= number_format($accountCount) ?></span>
                <span class="db-stat-label">اکانت</span>
            </div>
            <div class="db-stat">
                <span class="db-stat-num"><?= number_format($logCount) ?></span>
                <span class="db-stat-label">رکورد لاگ ترافیک</span>
            </div>
        </div>

        <!-- Truncate logs -->
        <div class="truncate-block">
            <div class="truncate-info">
                <strong>پاک کردن لاگ ترافیک</strong>
                <p class="muted">جدول <code>traffic_logs</code> پاک می‌شود. اکانت‌ها دست نخورده می‌مانند.</p>
            </div>
            <form method="post" class="settings-form truncate-form" onsubmit="return confirmTruncate(this, 'لاگ‌های ترافیک پاک شوند؟')">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action"     value="truncate_logs">
                <div class="truncate-pin-row">
                    <input type="text" name="truncate_pin" placeholder="رمز تأیید" maxlength="10" autocomplete="off" class="truncate-pin-input">
                    <button type="submit" class="btn btn-secondary">پاک کردن لاگ‌ها</button>
                </div>
            </form>
        </div>

        <div class="settings-sep"></div>

        <!-- Truncate all -->
        <div class="truncate-block truncate-block-danger">
            <div class="truncate-info">
                <strong class="text-danger">پاک کردن همه اکانت‌ها</strong>
                <p class="muted">تمام اکانت‌ها، لاگ‌ها پاک و peer‌ها از wg0.conf حذف می‌شوند. <strong>برگشت‌ناپذیر است.</strong></p>
            </div>
            <form method="post" class="settings-form truncate-form" onsubmit="return confirmTruncate(this, 'تمام اکانت‌ها و لاگ‌ها پاک شوند؟ این عمل برگشت‌ناپذیر است!')">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action"     value="truncate_all">
                <div class="truncate-pin-row">
                    <input type="text" name="truncate_pin" placeholder="رمز تأیید" maxlength="10" autocomplete="off" class="truncate-pin-input">
                    <button type="submit" class="btn btn-danger">پاک کردن همه</button>
                </div>
            </form>
        </div>

    </div>
</div>

<script>
function showTab(name) {
    document.querySelectorAll('.stab-panel').forEach(p => p.hidden = true);
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).hidden = false;
    event.currentTarget.classList.add('active');
}

function copyApiToken() {
    const input = document.getElementById('api-token-display');
    navigator.clipboard.writeText(input.value).then(function () {
        const btn = event.currentTarget;
        const orig = btn.textContent;
        btn.textContent = '✓ کپی شد';
        setTimeout(() => btn.textContent = orig, 2000);
    });
}

function generateAdminPath() {
    const bytes = new Uint8Array(4);
    crypto.getRandomValues(bytes);
    const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('admin-login-path').value = 'adm-' + hex;
}

function confirmTruncate(form, msg) {
    const pin = form.querySelector('.truncate-pin-input').value;
    if (!pin) {
        alert('رمز تأیید را وارد کنید.');
        return false;
    }
    return confirm(msg);
}

(function () {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (!tab) {
        return;
    }

    const button = document.querySelector('.settings-tabs .stab[onclick="showTab(\'' + tab + '\')"]');
    const panel = document.getElementById('tab-' + tab);

    if (!button || !panel) {
        return;
    }

    document.querySelectorAll('.stab-panel').forEach(p => p.hidden = true);
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('active'));
    panel.hidden = false;
    button.classList.add('active');
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
