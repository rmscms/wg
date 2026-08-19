<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$account = $wgManager->getAccount($id);

if ($account === null) {
    flash('danger', 'اکانت یافت نشد.');
    redirect('/');
}

$pageTitle = 'ویرایش اکانت';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flash('danger', 'درخواست نامعتبر.');
        redirect('/edit.php?id=' . $id);
    }

    try {
        $volumeInput = trim((string) ($_POST['volume_limit'] ?? '0'));
        $volumeBytes = $volumeInput === '0' || $volumeInput === ''
            ? 0
            : WgPanel\Helpers::parseSize($volumeInput);

        $wgManager->updateAccount($id, [
            'name' => $_POST['name'] ?? $account['name'],
            'speed_limit_kbps' => (int) ($_POST['speed_limit_kbps'] ?? 0),
            'volume_limit_bytes' => $volumeBytes,
            'expiry_mode' => $_POST['expiry_mode'] ?? ($account['expiry_mode'] ?? 'fixed'),
            'expires_at' => $_POST['expires_at'] ?? null,
            'expiry_duration_days' => (int) ($_POST['expiry_duration_days'] ?? ($account['expiry_duration_days'] ?? 0)),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);

        flash('success', 'اکانت به‌روزرسانی شد.');
        redirect('/');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $account = $wgManager->getAccount($id) ?? $account;
}

$volumeDisplay = (int) $account['volume_limit_bytes'] > 0
    ? WgPanel\Helpers::formatBytes((int) $account['volume_limit_bytes'])
    : '0';
$expiryMode = (string) ($account['expiry_mode'] ?? 'fixed');
$badge = WgPanel\Helpers::statusBadge($account);
$online = $wgManager->getAccountOnlineStatus($account);
$volumePercent = WgPanel\Helpers::volumePercent($account);
$volumeUsedHuman = WgPanel\Helpers::formatBytes((int) $account['volume_used_bytes']);
$volumeLimitHuman = (int) $account['volume_limit_bytes'] > 0
    ? WgPanel\Helpers::formatBytes((int) $account['volume_limit_bytes'])
    : 'نامحدود';
$speedHuman = (int) $account['speed_limit_kbps'] > 0
    ? WgPanel\Helpers::formatSpeed((int) $account['speed_limit_kbps'])
    : 'نامحدود';

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= e($account['name']) ?></h1>
        <p class="muted">ویرایش تنظیمات اکانت WireGuard</p>
        <span class="badge badge-lg <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
    </div>
    <div class="actions">
        <a href="/" class="btn btn-secondary">بازگشت</a>
        <a href="/view.php?id=<?= (int) $account['id'] ?>" class="btn btn-secondary">QR / Config</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="edit-layout">
    <aside class="card edit-sidebar">
        <h2 class="edit-sidebar-title">خلاصه اکانت</h2>

        <div
            class="edit-online online-chip is-<?= e((string) ($online['state'] ?? ($online['online'] ? 'online' : 'offline'))) ?>"
            data-live-online="<?= (int) $account['id'] ?>"
            title="<?= e((string) ($online['title'] ?? '')) ?>"
        >
            <span class="online-dot <?= $online['online'] ? 'is-online' : (($online['label'] ?? '') === 'قطع' ? 'is-disconnected' : 'is-offline') ?>"></span>
            <div>
                <div class="edit-online-label online-label"><?= e($online['label']) ?></div>
                <div class="edit-online-meta online-meta muted">
                    <?= ($online['label'] ?? '') === 'قطع'
                        ? 'peer غیرفعال'
                        : ($online['relative'] !== '—' ? 'handshake: ' . e($online['relative']) : 'بدون handshake') ?>
                </div>
            </div>
        </div>

        <dl class="details edit-details">
            <dt>IP</dt>
            <dd><code><?= e($account['ip_address']) ?></code></dd>
            <dt>سرعت فعلی</dt>
            <dd><?= e($speedHuman) ?></dd>
            <dt>انقضا</dt>
            <dd><?= e(WgPanel\Helpers::formatExpiryDisplay($account)) ?></dd>
            <?php if (!empty($account['first_connected_at'])): ?>
                <dt>اولین اتصال</dt>
                <dd><?= e(WgPanel\Helpers::formatDateTime((string) $account['first_connected_at'])) ?></dd>
            <?php endif; ?>
        </dl>

        <div class="edit-volume">
            <div class="edit-volume-head">
                <span class="muted">مصرف حجم</span>
                <span><?= e($volumeUsedHuman) ?> / <?= e($volumeLimitHuman) ?></span>
            </div>
            <?php if ($volumePercent !== null): ?>
                <div class="progress-track">
                    <div
                        class="progress-fill <?= $volumePercent >= 90 ? 'progress-danger' : ($volumePercent >= 70 ? 'progress-warning' : '') ?>"
                        style="width: <?= e((string) $volumePercent) ?>%"
                    ></div>
                </div>
                <div class="edit-volume-meta muted"><?= e((string) $volumePercent) ?>٪ مصرف شده</div>
            <?php else: ?>
                <p class="muted edit-volume-meta">حجم نامحدود</p>
            <?php endif; ?>
        </div>
    </aside>

    <div class="card edit-form-card">
        <form method="post" class="form form-sections">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <section class="form-section">
                <h3 class="form-section-title">اطلاعات پایه</h3>
                <label>
                    نام اکانت
                    <input type="text" name="name" required value="<?= e($account['name']) ?>" placeholder="مثلاً user-01">
                </label>
            </section>

            <section class="form-section">
                <h3 class="form-section-title">محدودیت‌ها</h3>
                <div class="form-grid-2">
                    <label>
                        محدودیت سرعت (Kbps)
                        <input type="number" name="speed_limit_kbps" min="0" step="1"
                               value="<?= e((string) $account['speed_limit_kbps']) ?>" placeholder="0 = نامحدود">
                        <small class="hint">5120 ≈ 5 Mbps</small>
                    </label>
                    <label>
                        محدودیت حجم
                        <input type="text" name="volume_limit" value="<?= e($volumeDisplay === '0' ? '0' : $volumeDisplay) ?>" placeholder="0 = نامحدود">
                        <small class="hint">10GB ، 500MB</small>
                    </label>
                </div>
                <div class="readonly-field">
                    <span class="readonly-field-label">مصرف فعلی (فقط نمایش)</span>
                    <span class="readonly-field-value"><?= e($volumeUsedHuman) ?></span>
                </div>
            </section>

            <section class="form-section">
                <h3 class="form-section-title">انقضا</h3>
                <div class="expiry-mode-tabs" role="radiogroup" aria-label="نوع انقضا">
                    <label class="expiry-mode-tab <?= $expiryMode === 'fixed' ? 'is-active' : '' ?>">
                        <input type="radio" name="expiry_mode" value="fixed" <?= $expiryMode === 'fixed' ? 'checked' : '' ?>>
                        <span class="expiry-mode-tab-title">تاریخ ثابت</span>
                        <span class="expiry-mode-tab-desc">تاریخ مشخص</span>
                    </label>
                    <label class="expiry-mode-tab <?= $expiryMode === 'first_connect' ? 'is-active' : '' ?>">
                        <input type="radio" name="expiry_mode" value="first_connect" <?= $expiryMode === 'first_connect' ? 'checked' : '' ?>>
                        <span class="expiry-mode-tab-title">اولین اتصال</span>
                        <span class="expiry-mode-tab-desc">شمارش از handshake</span>
                    </label>
                </div>

                <div id="expiry-fixed-block" class="expiry-panel">
                    <label>
                        تاریخ انقضا
                        <input type="datetime-local" name="expires_at"
                               value="<?= $account['expires_at'] ? e(date('Y-m-d\TH:i', strtotime((string) $account['expires_at']))) : '' ?>">
                        <small class="hint">خالی = بدون انقضا<?php if (!empty($account['expires_at'])): ?> — شمسی: <?= e(WgPanel\Helpers::formatDateTime((string) $account['expires_at'])) ?><?php endif; ?></small>
                    </label>
                </div>

                <div id="expiry-first-connect-block" class="expiry-panel" hidden>
                    <label>
                        مدت اعتبار (روز)
                        <input type="number" name="expiry_duration_days" min="1" step="1"
                               value="<?= e((string) ((int) ($account['expiry_duration_days'] ?? 30) ?: 30)) ?>">
                    </label>
                    <?php if (!empty($account['first_connected_at'])): ?>
                        <p class="hint expiry-note">اولین اتصال ثبت شده: <strong><?= e(WgPanel\Helpers::formatDateTime((string) $account['first_connected_at'])) ?></strong></p>
                    <?php else: ?>
                        <p class="hint expiry-note">تا قبل از اولین اتصال، انقضا شروع نمی‌شود.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="form-section form-section-last">
                <h3 class="form-section-title">وضعیت</h3>
                <label class="toggle-field">
                    <input type="checkbox" name="is_active" class="toggle-input" <?= (int) $account['is_active'] === 1 ? 'checked' : '' ?>>
                    <span class="toggle-switch" aria-hidden="true"></span>
                    <span class="toggle-label">
                        <strong>اکانت فعال</strong>
                        <small class="hint">غیرفعال = قطع peer از WireGuard</small>
                    </span>
                </label>
            </section>

            <div class="form-actions">
                <a href="/" class="btn btn-secondary">انصراف</a>
                <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const tabs = document.querySelectorAll('.expiry-mode-tab input[type="radio"]');
    const fixedBlock = document.getElementById('expiry-fixed-block');
    const firstBlock = document.getElementById('expiry-first-connect-block');

    function toggleExpiryFields() {
        const selected = document.querySelector('.expiry-mode-tab input[type="radio"]:checked');
        const isFirst = selected && selected.value === 'first_connect';

        fixedBlock.hidden = isFirst;
        firstBlock.hidden = !isFirst;

        document.querySelectorAll('.expiry-mode-tab').forEach(function (tab) {
            const input = tab.querySelector('input[type="radio"]');
            tab.classList.toggle('is-active', input && input.checked);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('change', toggleExpiryFields);
    });

    toggleExpiryFields();
})();
</script>

<script src="/assets/live-status.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
