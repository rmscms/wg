<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$configPath   = dirname(__DIR__) . '/config/config.php';
$configWriter = new WgPanel\ConfigWriter($configPath);

/* ═══════════════════════════════════════════════════
   POST handlers
   ═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flash('danger', 'درخواست نامعتبر.');
        redirect('/settings.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        /* ── WireGuard settings ── */
        if ($action === 'save_wireguard') {
            $configWriter->update([
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
        }

        /* ── App settings ── */
        elseif ($action === 'save_app') {
            $baseUrl = rtrim(trim((string) ($_POST['subscribe_base_url'] ?? '')), '/');
            $configWriter->update([
                'app' => [
                    'name'               => trim((string) ($_POST['app_name'] ?? 'WireGuard Panel')),
                    'subscribe_base_url' => $baseUrl,
                    'timezone'           => trim((string) ($_POST['timezone'] ?? 'Asia/Tehran')),
                ],
            ]);
            flash('success', 'تنظیمات پنل ذخیره شد.');
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
                $configWriter->update(['admin' => $changes]);

                $msg = 'اطلاعات مدیر ذخیره شد.';
                if ($loginPathChanged) {
                    $freshConfig = $configWriter->read();
                    $msg .= ' مسیر ورود: ' . WgPanel\AdminPath::url($freshConfig);
                }
                flash('success', $msg);
            } else {
                flash('success', 'تغییری اعمال نشد.');
            }
        }

        /* ── API settings ── */
        elseif ($action === 'save_api') {
            $enabled = isset($_POST['api_enabled']);
            $configWriter->update(['api' => ['enabled' => $enabled]]);
            flash('success', 'تنظیمات API ذخیره شد.');
        }

        elseif ($action === 'regen_token') {
            $token = bin2hex(random_bytes(32));
            $configWriter->update(['api' => ['token' => $token]]);
            flash('success', 'توکن API جدید ساخته شد.');
        }

        /* ── Backup settings ── */
        elseif ($action === 'save_backup') {
            $interval = (int) ($_POST['interval_hours'] ?? 24);
            $allowedIntervals = WgPanel\BackupManager::intervalOptions();

            if (!in_array($interval, $allowedIntervals, true)) {
                throw new RuntimeException('بازه زمانی بک‌آپ نامعتبر است.');
            }

            $configWriter->update([
                'backup' => [
                    'enabled' => isset($_POST['backup_enabled']),
                    'interval_hours' => $interval,
                    'include_wg_conf' => isset($_POST['include_wg_conf']),
                    'include_database' => isset($_POST['include_database']),
                    'retention_count' => max(1, min(100, (int) ($_POST['retention_count'] ?? 14))),
                ],
            ]);
            flash('success', 'تنظیمات بک‌آپ ذخیره شد.');
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
        }

        elseif ($action === 'delete_backup') {
            $filename = basename((string) ($_POST['backup_file'] ?? ''));
            backupManager()->delete($filename);
            flash('success', 'بک‌آپ حذف شد.');
        }

        /* ── Database truncate ── */
        elseif ($action === 'truncate_logs') {
            $pin = (string) ($_POST['truncate_pin'] ?? '');
            if ($pin !== '1565') {
                throw new RuntimeException('رمز تأیید اشتباه است.');
            }
            $db->exec('DELETE FROM traffic_logs');
            flash('success', 'جدول traffic_logs پاک شد.');
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
        }

    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect('/settings.php' . (in_array($action, ['save_backup', 'run_backup', 'delete_backup'], true) ? '?tab=backup' : ''));
}

/* ═══════════════════════════════════════════════════
   Load fresh config for display
   ═══════════════════════════════════════════════════ */
$cfg = $configWriter->read();
$wg  = $cfg['wireguard'] ?? [];
$app = $cfg['app']        ?? [];
$api = $cfg['api']        ?? [];
$adm = $cfg['admin']      ?? [];
$backup = $cfg['backup']  ?? [];
$adminLoginPath = WgPanel\AdminPath::url($cfg);
$backupManager = backupManager();
$backupList = $backupManager->listBackups();
$backupDir = dirname(__DIR__) . '/storage/backups';
$backupDirWritable = is_dir($backupDir) ? is_writable($backupDir) : is_writable(dirname($backupDir));
$backupIntervals = [
    6 => 'هر ۶ ساعت',
    12 => 'هر ۱۲ ساعت',
    24 => 'روزانه (۲۴ ساعت)',
    48 => 'هر ۲ روز',
    168 => 'هفتگی',
];

$logCount     = (int) $db->query('SELECT COUNT(*) FROM traffic_logs')->fetchColumn();
$accountCount = (int) $db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$configWritable = is_writable($configPath);

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

<?php if (!$configWritable): ?>
<div class="alert alert-danger">
    فایل config.php قابل نوشتن نیست. برای فعال کردن ذخیره تنظیمات روی سرور اجرا کنید:
    <code>chmod 660 <?= e($configPath) ?></code>
</div>
<?php endif; ?>

<!-- ── Tab nav ── -->
<div class="settings-tabs">
    <button class="stab active" onclick="showTab('wg')">🔒 WireGuard</button>
    <button class="stab" onclick="showTab('app')">⚙️ پنل</button>
    <button class="stab" onclick="showTab('admin')">👤 مدیر</button>
    <button class="stab" onclick="showTab('api')">🔑 API</button>
    <button class="stab" onclick="showTab('backup')">💾 بک‌آپ</button>
    <button class="stab stab-danger" onclick="showTab('db')">🗄️ دیتابیس</button>
</div>

<!-- ═══ WireGuard ═══ -->
<div id="tab-wg" class="stab-panel">
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
                <button type="submit" class="btn btn-primary" <?= $configWritable ? '' : 'disabled' ?>>ذخیره تنظیمات WireGuard</button>
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
                <button type="submit" class="btn btn-primary" <?= $configWritable ? '' : 'disabled' ?>>ذخیره تنظیمات پنل</button>
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
                <button type="submit" class="btn btn-primary" <?= $configWritable ? '' : 'disabled' ?>>ذخیره اطلاعات مدیر</button>
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
                <button type="submit" class="btn btn-primary" <?= $configWritable ? '' : 'disabled' ?>>ذخیره تنظیمات API</button>
            </div>
        </form>

        <div class="settings-sep"></div>

        <form method="post" class="settings-form" onsubmit="return confirm('یک توکن جدید ساخته می‌شود و توکن قبلی دیگر کار نخواهد کرد. ادامه می‌دهید؟');">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action"     value="regen_token">
            <p class="muted" style="margin-bottom:.75rem">ساخت توکن جدید — توکن فعلی بلافاصله نامعتبر می‌شود.</p>
            <button type="submit" class="btn btn-secondary" <?= $configWritable ? '' : 'disabled' ?>>🔄 ساخت توکن جدید</button>
        </form>
    </div>
</div>

<!-- ═══ Backup ═══ -->
<div id="tab-backup" class="stab-panel" hidden>
    <div class="card settings-card">
        <h2>بک‌آپ خودکار</h2>
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
                <button type="submit" class="btn btn-primary" <?= $configWritable ? '' : 'disabled' ?>>ذخیره تنظیمات بک‌آپ</button>
            </div>
        </form>

        <div class="settings-sep"></div>

        <h2>بک‌آپ دستی</h2>
        <?php if (!$backupDirWritable): ?>
        <div class="alert alert-danger">
            پوشه بک‌آپ قابل نوشتن نیست. روی سرور اجرا کنید:
            <code>mkdir -p <?= e($backupDir) ?> && chown www-data:www-data <?= e($backupDir) ?> && chmod 750 <?= e($backupDir) ?></code>
        </div>
        <?php endif; ?>

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
                <button type="submit" class="btn btn-secondary" <?= $backupDirWritable ? '' : 'disabled' ?>>📦 بک‌آپ الآن</button>
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
        <?php endif; ?>
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
