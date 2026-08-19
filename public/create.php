<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'ایجاد اکانت';
$bodyClass = 'create-page';
$errors = [];

$expiryMode = (string) ($_POST['expiry_mode'] ?? 'fixed');
$wg = $config['wireguard'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flash('danger', 'درخواست نامعتبر.');
        redirect('/create.php');
    }

    try {
        $volumeInput = trim((string) ($_POST['volume_limit'] ?? '0'));
        $volumeBytes = $volumeInput === '0' || $volumeInput === ''
            ? 0
            : WgPanel\Helpers::parseSize($volumeInput);

        $account = $wgManager->createAccount([
            'name' => $_POST['name'] ?? '',
            'speed_limit_kbps' => (int) ($_POST['speed_limit_kbps'] ?? 0),
            'volume_limit_bytes' => $volumeBytes,
            'expiry_mode' => $_POST['expiry_mode'] ?? 'fixed',
            'expires_at' => $_POST['expires_at'] ?? null,
            'expiry_duration_days' => (int) ($_POST['expiry_duration_days'] ?? 0),
        ]);

        flash('success', 'اکانت «' . $account['name'] . '» با موفقیت ایجاد شد.');
        redirect('/view.php?id=' . (int) $account['id']);
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $expiryMode = (string) ($_POST['expiry_mode'] ?? 'fixed');
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>ایجاد اکانت جدید</h1>
        <p class="muted">تنظیمات WireGuard و محدودیت‌ها را مشخص کنید</p>
        <span class="create-badge">اکانت جدید</span>
    </div>
    <div class="actions">
        <a href="/" class="btn btn-secondary">بازگشت</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="edit-layout create-layout">
    <aside class="card edit-sidebar create-sidebar">
        <h2 class="edit-sidebar-title">راهنمای ایجاد</h2>

        <ul class="create-tips">
            <li class="create-tip">
                <span class="create-tip-icon">IP</span>
                <span>آدرس IP به‌صورت خودکار از subnet تخصیص داده می‌شود.</span>
            </li>
            <li class="create-tip">
                <span class="create-tip-icon">WG</span>
                <span>کلیدها و peer به‌صورت خودکار روی سرور WireGuard اعمال می‌شوند.</span>
            </li>
            <li class="create-tip">
                <span class="create-tip-icon">Sub</span>
                <span>بعد از ایجاد، لینک subscribe و QR در صفحه جزئیات نمایش داده می‌شود.</span>
            </li>
        </ul>

        <div class="create-ref-block">
            <h4>Subnet سرور</h4>
            <dl class="create-ref-grid">
                <div class="create-ref-row">
                    <dt>CIDR</dt>
                    <dd><code><?= e((string) ($wg['subnet'] ?? '—')) ?></code></dd>
                </div>
                <div class="create-ref-row">
                    <dt>IP سرور</dt>
                    <dd><code><?= e((string) ($wg['server_ip'] ?? '—')) ?></code></dd>
                </div>
            </dl>
        </div>

        <div class="create-ref-block">
            <h4>مثال محدودیت‌ها</h4>
            <dl class="create-ref-grid">
                <div class="create-ref-row">
                    <dt>سرعت</dt>
                    <dd><code>5120</code> ≈ 5 Mbps</dd>
                </div>
                <div class="create-ref-row">
                    <dt>حجم</dt>
                    <dd><code>10GB</code> ، <code>500MB</code></dd>
                </div>
                <div class="create-ref-row">
                    <dt>نامحدود</dt>
                    <dd><code>0</code></dd>
                </div>
            </dl>
        </div>
    </aside>

    <div class="card edit-form-card">
        <form method="post" class="form form-sections">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <section class="form-section">
                <h3 class="form-section-title">اطلاعات پایه</h3>
                <label>
                    نام اکانت
                    <input type="text" name="name" required placeholder="مثلاً user-01" value="<?= e($_POST['name'] ?? '') ?>" autofocus>
                    <small class="hint">نام نمایشی در پنل و اپ subscribe</small>
                </label>
            </section>

            <section class="form-section">
                <h3 class="form-section-title">محدودیت‌ها</h3>
                <div class="form-grid-2">
                    <label>
                        محدودیت سرعت (Kbps)
                        <input type="number" name="speed_limit_kbps" min="0" step="1"
                               placeholder="0 = نامحدود" value="<?= e($_POST['speed_limit_kbps'] ?? '0') ?>">
                        <small class="hint">5120 ≈ 5 Mbps</small>
                    </label>
                    <label>
                        محدودیت حجم
                        <input type="text" name="volume_limit" placeholder="0 = نامحدود"
                               value="<?= e($_POST['volume_limit'] ?? '0') ?>">
                        <small class="hint">10GB ، 500MB ، 0</small>
                    </label>
                </div>
            </section>

            <section class="form-section form-section-last">
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
                        تاریخ انقضا (شمسی)
                        <input type="text" name="expires_at" data-jalali-datetime placeholder="1404/05/28 23:59"
                               value="<?= e($_POST['expires_at'] ?? '') ?>">
                        <small class="hint">خالی = بدون انقضا — مثال: 1404/05/28 23:59</small>
                    </label>
                </div>

                <div id="expiry-first-connect-block" class="expiry-panel" hidden>
                    <label>
                        مدت اعتبار (روز)
                        <input type="number" name="expiry_duration_days" min="1" step="1" placeholder="مثلاً 30"
                               value="<?= e($_POST['expiry_duration_days'] ?? '30') ?>">
                    </label>
                    <p class="hint expiry-note">تا قبل از اولین اتصال WireGuard، انقضا شروع نمی‌شود.</p>
                </div>
            </section>

            <div class="form-actions">
                <a href="/" class="btn btn-secondary">انصراف</a>
                <button type="submit" class="btn btn-primary btn-create">ایجاد اکانت</button>
            </div>
            <p class="create-submit-hint">بعد از ایجاد، به صفحه QR و config هدایت می‌شوید.</p>
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

<?php require __DIR__ . '/includes/footer.php'; ?>
