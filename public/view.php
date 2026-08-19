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

$pageTitle       = 'اکانت: ' . $account['name'];
$badge           = WgPanel\Helpers::statusBadge($account);
$subscribePanelUrl = $wgManager->buildSubscribePanelUrl($account);
$online          = $wgManager->getAccountOnlineStatus($account);

$configError = null;
try {
    $config = $wgManager->buildClientConfig($account);
} catch (Throwable $e) {
    $config = null;
    $configError = $e->getMessage();
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= e($account['name']) ?></h1>
        <p class="muted">کانفیگ و لینک‌های اشتراک</p>
    </div>
    <div class="actions">
        <a href="/" class="btn btn-secondary">بازگشت</a>
        <a href="/edit.php?id=<?= (int) $account['id'] ?>" class="btn btn-secondary">ویرایش</a>
        <a href="/download.php?id=<?= (int) $account['id'] ?>" class="btn btn-primary">دانلود .conf</a>
    </div>
</div>

<!-- ─── Status strip ─── -->
<div class="card" style="margin-bottom:.75rem">
    <div
        class="online-status online-chip is-<?= e((string) ($online['state'] ?? ($online['online'] ? 'online' : 'offline'))) ?>"
        data-live-online="<?= (int) $account['id'] ?>"
        title="<?= e((string) ($online['title'] ?? '')) ?>"
    >
        <span class="online-dot <?= $online['online'] ? 'is-online' : (($online['label'] ?? '') === 'قطع' ? 'is-disconnected' : 'is-offline') ?>"></span>
        <div class="online-chip-text">
            <div class="connection-live-title online-label"><?= e($online['label']) ?></div>
            <div class="connection-live-meta online-meta">
                <?= ($online['label'] ?? '') === 'قطع'
                    ? 'peer غیرفعال'
                    : ($online['relative'] !== '—' ? 'handshake: ' . e($online['relative']) : 'بدون handshake') ?>
            </div>
        </div>
        <span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span>
    </div>
    <p class="live-indicator-note muted" style="margin:.6rem 0 0;font-size:.78rem">به‌روزرسانی زنده هر ۱۰ ثانیه (بر اساس handshake WireGuard)</p>
</div>

<!-- ─── Details grid ─── -->
<div class="view-grid" style="margin-bottom:.75rem">
    <div class="card qr-card">
        <h2>QR کانفیگ</h2>
        <p class="muted" style="margin-bottom:.75rem">اسکن مستقیم در اپ رسمی WireGuard</p>
        <div class="qr-wrap">
            <img src="/qr.php?id=<?= (int) $account['id'] ?>" alt="WireGuard QR Code" width="300" height="300">
        </div>
    </div>

    <div class="card">
        <h2>جزئیات</h2>
        <dl class="details">
            <dt>IP</dt>
            <dd><code><?= e($account['ip_address']) ?></code></dd>
            <dt>وضعیت</dt>
            <dd><span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></dd>
            <dt>سرعت</dt>
            <dd><?= (int) $account['speed_limit_kbps'] > 0 ? e((string) $account['speed_limit_kbps']) . ' Kbps' : 'نامحدود' ?></dd>
            <dt>حجم</dt>
            <dd>
                <?= e(WgPanel\Helpers::formatBytes((int) $account['volume_used_bytes'])) ?>
                /
                <?= (int) $account['volume_limit_bytes'] > 0
                    ? e(WgPanel\Helpers::formatBytes((int) $account['volume_limit_bytes']))
                    : 'نامحدود' ?>
            </dd>
            <dt>انقضا</dt>
            <dd>
                <?= e(WgPanel\Helpers::formatExpiryDisplay($account)) ?>
                <?php if (!empty($account['first_connected_at'])): ?>
                    <br><small class="muted">اولین اتصال: <?= e(WgPanel\Helpers::formatDateTime((string) $account['first_connected_at'])) ?></small>
                <?php endif; ?>
            </dd>
        </dl>
    </div>
</div>

<!-- ─── Subscription links tabs ─── -->
<div class="card view-sub-card">
    <div class="view-sub-header">
        <h2>لینک‌های اشتراک</h2>
        <p class="muted">یکی از روش‌های زیر را انتخاب کنید</p>
    </div>

    <div class="view-tabs" role="tablist">
        <button class="view-tab active" onclick="switchTab(this,'tab-wg')" role="tab" aria-selected="true">
            <span class="view-tab-icon">🔒</span>
            <span class="view-tab-label">WireGuard Config</span>
        </button>
        <button class="view-tab" onclick="switchTab(this,'tab-web')" role="tab" aria-selected="false">
            <span class="view-tab-icon">🌐</span>
            <span class="view-tab-label">Web Link</span>
        </button>
    </div>

    <!-- WireGuard Config -->
    <div id="tab-wg" class="view-tab-panel" role="tabpanel">
        <div class="view-tab-body">
            <div class="view-tab-text">
                <div class="view-tab-apps">
                    <span class="app-chip">WireGuard Official</span>
                </div>
                <p class="muted" style="margin:.65rem 0 .85rem">فایل .conf را دانلود کرده یا QR را از اپ رسمی WireGuard اسکن کنید.</p>
                <div class="view-actions-row">
                    <a href="/download.php?id=<?= (int) $account['id'] ?>" class="btn btn-primary btn-small">
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        دانلود .conf
                    </a>
                </div>
            </div>
            <div class="view-tab-qr">
                <img src="/qr.php?id=<?= (int) $account['id'] ?>" alt="WireGuard Config QR" width="200" height="200">
                <p class="view-qr-label">QR کانفیگ WireGuard</p>
            </div>
        </div>
    </div>

    <!-- Web Link -->
    <div id="tab-web" class="view-tab-panel" role="tabpanel" hidden>
        <div class="view-tab-body">
            <div class="view-tab-text">
                <div class="view-tab-apps">
                    <span class="app-chip">مرورگر</span>
                    <span class="app-chip">صفحه کاربری</span>
                </div>
                <p class="muted" style="margin:.65rem 0 .4rem">این لینک را به کاربر بدهید تا حجم، سرعت و انقضا را در مرورگر ببیند.</p>
                <div class="view-link-row">
                    <input type="text" id="link-web" readonly value="<?= e($subscribePanelUrl) ?>">
                    <button type="button" class="btn-copy-link" onclick="copyLink('link-web',this)">
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><rect x="5" y="5" width="9" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M11 5V3a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        کپی
                    </button>
                </div>
                <div class="view-actions-row" style="margin-top:.65rem">
                    <a href="<?= e($subscribePanelUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-small">
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M7 3H3a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V9M10 2h4m0 0v4m0-4L7 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        باز کردن
                    </a>
                </div>
            </div>
            <div class="view-tab-qr">
                <img src="/panel-qr.php?id=<?= (int) $account['id'] ?>" alt="Web Panel QR" width="200" height="200">
                <p class="view-qr-label">QR لینک وب</p>
            </div>
        </div>
    </div>
</div>

<!-- ─── Config text ─── -->
<div class="card">
    <h2>متن کانفیگ</h2>
    <?php if ($configError !== null): ?>
        <div class="alert alert-danger" style="margin:0">
            <strong>خطا در ساخت کانفیگ:</strong> <?= e($configError) ?>
            <br><small style="opacity:.7">کلید عمومی سرور را در <code>config.php</code> تنظیم کنید: <code>'server_public_key' => '...'</code></small>
        </div>
    <?php else: ?>
        <pre class="config-block"><?= e($config) ?></pre>
    <?php endif; ?>
</div>

<script>
function switchTab(btn, panelId) {
    document.querySelectorAll('.view-tab').forEach(t => {
        t.classList.remove('active');
        t.setAttribute('aria-selected','false');
    });
    document.querySelectorAll('.view-tab-panel').forEach(p => p.hidden = true);
    btn.classList.add('active');
    btn.setAttribute('aria-selected','true');
    document.getElementById(panelId).hidden = false;
}

function copyLink(id, btn) {
    const val = document.getElementById(id).value;
    navigator.clipboard.writeText(val).then(function () {
        const orig = btn.innerHTML;
        btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M3 8l4 4 6-7" stroke="#22c55e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg> کپی شد';
        btn.classList.add('copied');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('copied'); }, 2000);
    });
}
</script>

<script src="/assets/live-status.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
